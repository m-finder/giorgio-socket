<?php

namespace GiorgioSocket\Services\Handlers;

use GiorgioSocket\Services\ConnectionRegistry;
use GiorgioSocket\Services\EventStore;
use GiorgioSocket\Services\Handlers\Interfaces\SocketMessageInterface;
use GiorgioSocket\Services\Native\Frame as NativeFrame;
use GiorgioSocket\Services\Native\Server as NativeServer;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

/** 首帧鉴权、心跳续期和投递；业务消息默认交给已鉴权的 HTTP 接口。 */
class SocketMessage implements SocketMessageInterface
{
    public function handle(Server|NativeServer $server, Frame|NativeFrame $frame): void
    {
        try {
            if (strlen($frame->data) > (int) config('socket.max_message_bytes', 65536)) {
                $server->disconnect($frame->fd, 1009, 'Message too large');

                return;
            }
            $data = json_decode($frame->data, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($data) || ! is_string($data['type'] ?? null)) {
                throw new \InvalidArgumentException('Invalid packet.');
            }
            $registry = app(ConnectionRegistry::class);
            if ($data['type'] === 'connect') {
                if ($registry->user($frame->fd) !== null) {
                    throw new \InvalidArgumentException('Connection is already authenticated.');
                }
                // 身份来自服务端鉴权器，忽略报文里自报的 user_id。
                $authenticate = config('socket.authenticate');
                $id = $authenticate ? app($authenticate)($data) : null;
                if (! is_int($id) && ! is_string($id)) {
                    $server->disconnect($frame->fd, 1008, 'Authentication required');

                    return;
                }
                $registry->bind($frame->fd, (string) $id, $data);
                $registry->presence((string) $id, true);
                $server->push($frame->fd, json_encode(['type' => 'connected', 'user_id' => $id], JSON_THROW_ON_ERROR));
                while (($pending = $registry->pending((string) $id)) !== null) {
                    if (! $server->isEstablished($frame->fd) || ! $server->push($frame->fd, $pending)) {
                        break;
                    }
                    $registry->acknowledge((string) $id);
                }

                return;
            }
            $id = $registry->user($frame->fd);
            if ($id === null) {
                $server->disconnect($frame->fd, 1008, 'Authentication required');

                return;
            }
            // 每次操作重新检查登录令牌，保证注销或吊销及时生效。
            $authenticate = config('socket.authenticate');
            if (! $authenticate || (string) app($authenticate)($registry->credentials($frame->fd), true) !== $id) {
                $server->disconnect($frame->fd, 1008, 'Authentication expired');

                return;
            }
            if ($data['type'] === 'ping' || ($data['data'] ?? null) === 'heart_beat') {
                $server->push($frame->fd, json_encode(['type' => 'pong'], JSON_THROW_ON_ERROR));
            } elseif ($data['type'] === 'close') {
                $server->disconnect($frame->fd, 1000, 'Client closed');
            } elseif ($data['type'] === 'sync') {
                if (! is_int($data['after'] ?? null) || $data['after'] < 0
                    || ! is_int($data['request_id'] ?? null) || $data['request_id'] < 1) {
                    throw new \InvalidArgumentException('Invalid event cursor or request ID.');
                }
                // Read only the authenticated recipient, never an ID supplied by the client.
                $reader = config('socket.events.reader');
                $page = $reader ? app($reader)($id, $data['after']) : app(EventStore::class)->after($id, $data['after']);
                do {
                    $packet = json_encode(['type' => 'events', 'request_id' => $data['request_id']] + $page, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                    if (strlen($packet) <= (int) config('socket.max_message_bytes', 65536)) {
                        $server->push($frame->fd, $packet);
                        break;
                    }
                    if (count($page['events']) <= 1) {
                        $server->disconnect($frame->fd, 1009, 'Event too large');
                        break;
                    }
                    array_pop($page['events']);
                    $page['cursor'] = $page['events'][count($page['events']) - 1]['id'];
                    $page['hasMore'] = true;
                } while (true);
            } elseif ($data['type'] === 'message' && config('socket.allow_client_messages', false) && isset($data['to']) && $data['to'] !== 'all') {
                $this->deliver($server, (string) $data['to'], json_encode(array_merge($data, ['user_id' => $id]), JSON_THROW_ON_ERROR));
            } else {
                $server->push($frame->fd, json_encode(['type' => 'error', 'message' => 'Unsupported packet. Use the authenticated application API.'], JSON_THROW_ON_ERROR));
            }
        } catch (\JsonException|\InvalidArgumentException) {
            $server->push($frame->fd, '{"type":"error","message":"Invalid packet."}');
        } catch (\Throwable $error) {
            report($error);
            $server->disconnect($frame->fd, 1011, 'Service unavailable');
        }
    }

    /** 向所有在线连接投递；全部失败时才进入离线队列。 */
    public function deliver(Server|NativeServer $server, string $userId, string $message): void
    {
        $registry = app(ConnectionRegistry::class);
        $delivered = false;
        foreach ($registry->connections($userId) as $fd) {
            if ($server->isEstablished($fd) && $server->push($fd, $message)) {
                $delivered = true;
            } else {
                $registry->remove($fd);
            }
        }
        if (! $delivered) {
            $registry->enqueue($userId, $message);
        }
    }
}

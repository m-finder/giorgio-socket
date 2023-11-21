<?php

namespace GiorgioSocket\Services\Handlers;

use GiorgioSocket\Services\Handlers\Interfaces\SocketMessageInterface;
use Illuminate\Support\Facades\Redis;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

class SocketMessage implements SocketMessageInterface
{

    public function handle(Server $server, Frame $frame): void
    {

        $data = json_decode($frame->data, true);

        if (config('socket.log')) {
            info('socket message', [
                'from' => $frame->fd,
                'to' => $data['to'] ?? '',
                'data' => $data,
            ]);
        }

        match ($data['type']) {
            'connect' => $this->connect($server, $data['user_id'], $frame->fd),
            'message', 'system' => $this->send($server, $data['to'], $frame->data),
            'close' => $this->close($frame->fd),
        };

    }

    protected function connect(Server $server, $userId, $from): void
    {
        if(config('socket.log')){
            info('socket bind', [
                'user_id' => $userId,
                'from' => $from
            ]);
        }

        // bind user_id to fd
        if (!empty(Redis::client()->zscore('socket', $from))) {
            Redis::client()->zrem('socket', $from);
        }
        Redis::client()->zadd('socket', $from, $userId);

        // send offline message
        while ($message = Redis::client()->rpop('socket_' . $userId . '_offline_messages')) {
            $to = json_decode($message, true)['to'] ?? '';
            if (!empty($to)) {
                $this->send($server, $to, $message);
            }
        }
    }

    protected function close($from): void
    {
        Redis::client()->zrem('socket', $from);
    }

    protected function send(Server $server, $to, $data): void
    {
        // send message to all
        if ($to === 'all') {

            $ids = Redis::client()->zrange('socket', 0, -1);
            foreach ($ids as $to) {
                $this->push($server, $to, $data, $to);
            }

        } else {

            // send message to one
            $originalTo = $to;
            $to = Redis::client()->zscore('socket', $to);
            if (empty($to)) {
                logger('socket error', [
                    'msg' => 'user not found',
                    'data' => $data
                ]);
                return;
            }

            $this->push($server, $to, $data, $originalTo);
        }

    }

    protected function push($server, $to, $data, $userId): void
    {
        if ($server->exist(intval($to))) {
            $server->push(intval($to), $data);
        } else {
            // save offline message
            Redis::client()->lpush('socket_' . $userId . '_offline_messages', $data);
        }
    }
}
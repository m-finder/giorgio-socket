<?php

namespace GiorgioSocket\Services\Handlers;

use GiorgioSocket\Services\Handlers\Interfaces\SocketMessageInterface;
use Illuminate\Support\Facades\Redis;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

class SocketMessage implements SocketMessageInterface
{

    /**
     * socket server on message
     * @param Server $server
     * @param Frame $frame
     * @return void
     */
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
            'message', 'system' => $this->receive($server, $data['to'], $frame->data),
            'close' => $this->close($frame->fd),
        };

    }

    /**
     * socket message type is connect
     * @param Server $server
     * @param $userId
     * @param $from
     * @return void
     */

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
            info('offline read', json_decode($message, true));
            $to = json_decode($message, true)['to'] ?? '';
            if (!empty($to)) {
                $this->send($server, $from, $message, $userId);
            }
        }
    }

    /**
     * socket message type is close
     * @param $from
     * @return void
     */
    protected function close($from): void
    {
        Redis::client()->zrem('socket', $from);
    }

    /**
     * receive message
     * @param Server $server
     * @param $to
     * @param $data
     * @return void
     */
    protected function receive(Server $server, $to, $data): void
    {
        // send message to all
        if ($to === 'all') {

            $ids = Redis::client()->zrange('socket', 0, -1);
            foreach ($ids as $to) {
                $this->send($server, $to, $data, $to);
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

            $this->send($server, $to, $data, $originalTo);
        }

    }

    /**
     * send message
     * @param $server
     * @param $to
     * @param $data
     * @param $userId
     * @return void
     */

    protected function send($server, $to, $data, $userId): void
    {
        if ($server->exist(intval($to))) {
            $server->push(intval($to), $data);
        } else {
            // save offline message
            if (config('socket.log')){
                info('socket offline message', json_decode($data, true));
            }
            Redis::client()->lpush('socket_' . $userId . '_offline_messages', $data);
        }
    }
}
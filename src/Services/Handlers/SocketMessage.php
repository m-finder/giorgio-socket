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

        $from = $frame->fd;
        $data = json_decode($frame->data, true);
        $type = $data['type'];

        if (config('socket.log')) {
            info('socket message', [
                'from' => $from,
                'to' => $data['to'] ?? '',
                'data' => $data,
            ]);
        }

        match ($type) {
            'connect' => $this->connect($server, $data['user_id'], $from),
            'message', 'system' => $this->send($server, $data['to'], $frame->data),
            'close' => $this->close($from),
        };


    }

    private function connect(Server $server, $userId, $from): void
    {
        if(config('socket.log')){
            info('socket bind', [
                'user_id' => $userId,
                'from' => $from
            ]);
        }

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

    private function close($from): void
    {
        Redis::client()->zrem('socket', $from);
    }

    private function send(Server $server, $to, $data): void
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
                info('socket error', [
                    'msg' => 'user not found',
                    'data' => $data
                ]);
                return;
            }

            $this->push($server, $to, $data, $originalTo);
        }

    }

    private function push($server, $to, $data, $userId): void
    {
        if ($server->exist(intval($to))) {
            $server->push(intval($to), $data);
        } else {
            Redis::client()->lpush('socket_' . $userId . '_offline_messages', $data);
        }
    }
}
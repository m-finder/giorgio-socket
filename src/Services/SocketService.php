<?php

namespace GiorgioSocket\Services;

use Swoole\WebSocket\Server;

class SocketService
{
    public function start(): void
    {
        $server = new Server(config('socket.host', '0.0.0.0'), config('socket.port', 9501));

        // 监听 WebSocket 连接事件。
        $open = new (config('socket.handlers.open'));
        $server->on('open', function ($server, $request) use ($open) {
            $open->handle($server, $request);
        });

        // 监听 WebSocket 消息事件。
        $message = new (config('socket.handlers.message'));
        $server->on('message', function ($server, $frame) use ($message) {
            $message->handle($server, $frame);
        });

        // 监听 WebSocket 连接关闭事件。
        $close = new (config('socket.handlers.close'));
        $server->on('close', function ($server, $fd) use ($close) {
            $close->handle($server, $fd);
        });

        if (config('socket.log')) {
            info('socket server created');
        }
        $server->start();
    }
}
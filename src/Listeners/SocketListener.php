<?php

namespace GiorgioSocket\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;

class SocketListener implements ShouldQueue
{
    public string $queue = 'socket-listener';

    /**
     * 队列只负责调用同一个服务端推送客户端；失败抛出异常，由 Laravel 队列重试。
     */
    public function handle(object $event): void
    {
        $client = app(config('socket.handlers.client'));
        $client->handle($event);
    }
}

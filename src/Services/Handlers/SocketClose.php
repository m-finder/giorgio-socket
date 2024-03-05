<?php

namespace GiorgioSocket\Services\Handlers;

use GiorgioSocket\Services\Handlers\Interfaces\SocketCloseInterface;
use Swoole\WebSocket\Server;

class SocketClose implements SocketCloseInterface
{
    /**
     * socket server on close
     */
    public function handle(Server $server, $fd): void
    {
        if (config('socket.log')) {
            info("socket client-{$fd} is closed");
        }
    }
}

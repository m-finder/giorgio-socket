<?php

namespace GiorgioSocket\Services\Handlers;

use GiorgioSocket\Services\Handlers\Interfaces\SocketOpenInterface;

class SocketOpen implements SocketOpenInterface
{
    /**
     * socket server on open
     */
    public function handle($server, $request): void
    {
        if (config('socket.log')) {
            info("socket client-{$request->fd} is opened");
        }
    }
}

<?php

namespace GiorgioSocket\Services\Handlers\Interfaces;

use Swoole\WebSocket\Server;

interface SocketOpenInterface
{
    public function handle(Server $server, $request);
}

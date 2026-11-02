<?php

namespace GiorgioSocket\Services\Handlers\Interfaces;

use GiorgioSocket\Services\Native\Server as NativeServer;
use Swoole\WebSocket\Server;

interface SocketOpenInterface
{
    public function handle(Server|NativeServer $server, $request);
}

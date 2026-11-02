<?php

namespace GiorgioSocket\Services\Handlers\Interfaces;

use GiorgioSocket\Services\Native\Server as NativeServer;
use Swoole\WebSocket\Server;

interface SocketCloseInterface
{
    public function handle(Server|NativeServer $server, $fd);
}

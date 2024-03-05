<?php

namespace GiorgioSocket\Services\Handlers\Interfaces;

use Swoole\WebSocket\Server;

interface SocketCloseInterface
{
    public function handle(Server $server, $fd);
}

<?php

namespace GiorgioSocket\Services\Handlers\Interfaces;

use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

interface SocketMessageInterface
{
    public function handle(Server $server, Frame $frame);
}

<?php

namespace GiorgioSocket\Services\Handlers\Interfaces;

use GiorgioSocket\Services\Native\Frame as NativeFrame;
use GiorgioSocket\Services\Native\Server as NativeServer;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

interface SocketMessageInterface
{
    public function handle(Server|NativeServer $server, Frame|NativeFrame $frame);
}

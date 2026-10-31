<?php

namespace GiorgioSocket\Services\Handlers;

use GiorgioSocket\Services\ConnectionRegistry;
use GiorgioSocket\Services\Handlers\Interfaces\SocketCloseInterface;
use Swoole\WebSocket\Server;

/** 最后一个连接断开时才通知业务层用户离线。 */
class SocketClose implements SocketCloseInterface
{
    public function handle(Server $server, $fd): void
    {
        $registry = app(ConnectionRegistry::class);
        $id = $registry->remove((int) $fd);
        if ($id !== null && ! $registry->connections($id)) {
            $registry->presence($id, false);
        }
    }
}

<?php

namespace GiorgioSocket\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;

class SocketListener implements ShouldQueue
{
    public string $queue = 'socket-listener';


    /**
     * handle message from http api
     * @param object $event
     * @return void
     */
    public function handle(object $event): void
    {
        $client = new (config('socket.handlers.client'));
        $client->handle($event);
    }
}

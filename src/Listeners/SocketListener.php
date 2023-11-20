<?php

namespace GiorgioSocket\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;

class SocketListener implements ShouldQueue
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param object $event
     * @return void
     */
    public function handle(object $event): void
    {
        $client = new (config('socket.handlers.client'));
        $client->handle($event);
    }
}

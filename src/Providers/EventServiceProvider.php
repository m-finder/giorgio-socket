<?php

namespace GiorgioSocket\Providers;

use GiorgioSocket\Events\SocketEvent;
use GiorgioSocket\Listeners\SocketListener;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * socket event listener
     *
     * @var array[]
     */
    protected $listen = [
        SocketEvent::class => [
            SocketListener::class,
        ],
    ];
}

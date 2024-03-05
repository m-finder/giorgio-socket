<?php

namespace GiorgioSocket\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * socket service facade
 *
 * @method start() socket server start
 */
class Socket extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'socket';
    }
}

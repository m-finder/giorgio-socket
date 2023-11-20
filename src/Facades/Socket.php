<?php

namespace GiorgioSocket\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method start() socket server start
 */
class Socket extends Facade
{
    /**
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'socket';
    }
}
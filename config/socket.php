<?php

return [
    // socket host
    'host' => env('SOCKET_HOST', '0.0.0.0'),
    // socket port
    'port' => env('SOCKET_PORT', 9501),
    // is logging enabled?
    'log' => env('SOCKET_LOG', true),
    // system notice user id
    'system_id' => env('APP_NAME', 999999999),
    'system_name' => env('APP_NAME', 'System'),
    // event handlers
    'handlers' => [
        'open' => \GiorGioSocket\Services\Handlers\SocketOpen::class,
        'message' => \GiorGioSocket\Services\Handlers\SocketMessage::class,
        'close' => \GiorGioSocket\Services\Handlers\SocketClose::class,
        'client' => \GiorGioSocket\Services\Handlers\ClientSend::class,
    ],
];

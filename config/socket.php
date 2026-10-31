<?php

return [
    'host' => env('SOCKET_HOST', '127.0.0.1'),
    'port' => env('SOCKET_PORT', 9501),
    'log' => env('SOCKET_LOG', true),
    'system_id' => env('SOCKET_SYSTEM_ID', 999999999),
    'system_name' => env('SOCKET_SYSTEM_NAME', 'System'),
    'push_url' => env('SOCKET_PUSH_URL', 'http://127.0.0.1:9501/push'),
    // 仅用于服务端 /push，不能下发浏览器。
    'push_token' => env('SOCKET_PUSH_TOKEN', ''),
    'require_tls' => env('SOCKET_REQUIRE_TLS', true),
    'allow_insecure_loopback' => env('SOCKET_ALLOW_INSECURE_LOOPBACK', true),
    'push_ca_file' => env('SOCKET_PUSH_CA_FILE'),
    'tls' => [
        'enabled' => env('SOCKET_TLS_ENABLED', false),
        'cert_file' => env('SOCKET_TLS_CERT_FILE'),
        'key_file' => env('SOCKET_TLS_KEY_FILE'),
    ],
    'prefix' => env('SOCKET_PREFIX', 'giorgio-socket:9501:'),
    // 可调用服务类：__invoke(array $packet, bool $established = false): int|string|null。
    'authenticate' => null,
    'ticket_ttl' => env('SOCKET_TICKET_TTL', 60),
    'presence' => null,
    // 可选持久事件表；由 socket-migrations 发布，或指定已有兼容表。
    'events' => [
        'table' => env('SOCKET_EVENTS_TABLE', 'socket_events'),
        // 仅清理已发布超过保留时长的事件；由宿主调度 socket:prune-events。
        'retention_hours' => env('SOCKET_EVENTS_RETENTION_HOURS', 168),
        'prune_batch_size' => 1000,
    ],
    // 开启前须在自定义处理器中增加业务权限检查；IM 通过 HTTP 接口发送。
    'allow_client_messages' => false,
    'max_message_bytes' => 65536,
    'offline_limit' => 100,
    'offline_ttl' => 604800,
    'handlers' => [
        'open' => \GiorgioSocket\Services\Handlers\SocketOpen::class,
        'message' => \GiorgioSocket\Services\Handlers\SocketMessage::class,
        'close' => \GiorgioSocket\Services\Handlers\SocketClose::class,
        'client' => \GiorgioSocket\Services\Handlers\ClientSend::class,
    ],
];

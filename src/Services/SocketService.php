<?php

namespace GiorgioSocket\Services;

use GiorgioSocket\Services\Native\Server as NativeServer;
use Swoole\WebSocket\Server;

/** 启动 WebSocket，并在启动前检查 TLS 配置。 */
class SocketService
{
    public function driver(): string
    {
        $driver = config('socket.driver', 'auto');
        if (! in_array($driver, ['auto', 'swoole', 'native'], true)) {
            throw new \InvalidArgumentException('SOCKET_DRIVER must be auto, swoole or native.');
        }
        $available = PHP_OS_FAMILY !== 'Windows' && extension_loaded('swoole') && class_exists(Server::class);
        if ($driver === 'swoole' && ! $available) {
            throw new \RuntimeException('The swoole driver requires Swoole WebSocket Server on a non-Windows platform. Use auto or native.');
        }

        return $driver === 'auto' ? ($available ? 'swoole' : 'native') : $driver;
    }

    public function start(): void
    {
        $configuration = $this->configuration();
        $host = (string) config('socket.host', '127.0.0.1');
        $port = (int) config('socket.port', 9501);
        $server = $configuration['driver'] === 'swoole'
            ? new Server($host, $port, SWOOLE_PROCESS, $configuration['socket_type'])
            : new NativeServer($host, $port);
        $server->set($configuration['settings']);
        app(ConnectionRegistry::class)->reset();
        $open = app(config('socket.handlers.open'));
        $message = app(config('socket.handlers.message'));
        $close = app(config('socket.handlers.close'));
        $server->on('open', fn ($server, $request) => $open->handle($server, $request));
        $server->on('message', fn ($server, $frame) => $message->handle($server, $frame));
        $server->on('close', fn ($server, $fd) => $close->handle($server, $fd));
        $server->on('request', function ($request, $response) use ($server, $message) {
            $this->push($server, $message, $request, $response);
        });
        $server->start();
    }

    /** Validate listener security before binding a port or changing connection state. */
    public function configuration(): array
    {
        $driver = $this->driver();
        $settings = ['heartbeat_check_interval' => 30, 'heartbeat_idle_time' => 90, 'package_max_length' => (int) config('socket.max_message_bytes', 65536)];
        $socketType = $driver === 'swoole' ? SWOOLE_SOCK_TCP : null;
        if (config('socket.tls.enabled', false)) {
            if (! extension_loaded('openssl')) {
                throw new \RuntimeException('Socket TLS requires the OpenSSL PHP extension.');
            }
            $certFile = (string) config('socket.tls.cert_file');
            $keyFile = (string) config('socket.tls.key_file');
            if (! is_file($certFile) || ! is_readable($certFile) || ! is_file($keyFile) || ! is_readable($keyFile)) {
                throw new \RuntimeException('Socket TLS requires readable certificate and private key files.');
            }
            if (! openssl_x509_check_private_key(file_get_contents($certFile), file_get_contents($keyFile))) {
                throw new \RuntimeException('Socket TLS certificate and private key do not match.');
            }
            $settings += ['ssl_cert_file' => $certFile, 'ssl_key_file' => $keyFile];
            if ($driver === 'swoole') {
                $socketType |= SWOOLE_SSL;
                $settings['ssl_protocols'] = SWOOLE_SSL_TLSv1_2 | SWOOLE_SSL_TLSv1_3;
            }
        } elseif (config('socket.require_tls', true)
            && ! (config('socket.allow_insecure_loopback', true) && TransportSecurity::isLoopback((string) config('socket.host', '127.0.0.1')))) {
            throw new \RuntimeException('Public socket listeners require TLS. Configure a certificate or bind to loopback behind a TLS proxy.');
        }

        return ['driver' => $driver, 'socket_type' => $socketType, 'settings' => $settings];
    }

    /** Authenticated server-to-server push; never trust a WebSocket system packet. */
    public function push($server, $message, $request, $response): void
    {
        $response->header('Content-Type', 'application/json');
        $secret = (string) config('socket.push_token', '');
        if ($secret === '' || ! hash_equals('Bearer '.$secret, $request->header['authorization'] ?? '')) {
            $response->status(401);
            $response->end('{"error":"Unauthorized"}');

            return;
        }
        if (($request->server['request_method'] ?? '') !== 'POST' || ($request->server['request_uri'] ?? '') !== '/push') {
            $response->status(404);
            $response->end('{"error":"Not found"}');

            return;
        }
        try {
            $raw = $request->rawContent();
            if (strlen($raw) > (int) config('socket.max_message_bytes', 65536)) {
                throw new \InvalidArgumentException('Message too large.');
            }
            $params = $request->post ?: json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($params) || (! is_string($params['to'] ?? null) && ! is_int($params['to'] ?? null)) || ! array_key_exists('message', $params)) {
                throw new \InvalidArgumentException('Invalid recipient or message.');
            }
            $packet = json_encode(['type' => 'system', 'data' => $params['message']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $recipients = $params['to'] === 'all' ? app(ConnectionRegistry::class)->users() : [(string) $params['to']];
            foreach ($recipients as $id) {
                $message->deliver($server, (string) $id, $packet);
            }
            $response->end('{"ok":true}');
        } catch (\JsonException|\InvalidArgumentException) {
            $response->status(422);
            $response->end('{"error":"Invalid packet"}');
        } catch (\Throwable $error) {
            report($error);
            $response->status(503);
            $response->end('{"error":"Service unavailable"}');
        }
    }
}

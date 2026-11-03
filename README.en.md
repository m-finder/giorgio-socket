# Giorgio Socket

<p align="center"><img src="https://m-finder.github.io/images/avatar.jpeg"></p>
<p align="center">
<img src="https://img.shields.io/badge/Author-m--finder-red" alt="Author">
<a href="https://packagist.org/packages/wu/giorgio-socket"><img src="https://img.shields.io/badge/License-MIT-green" alt="License"></a>
<a href="https://packagist.org/packages/wu/giorgio-socket"><img src="https://img.shields.io/packagist/v/wu/giorgio-socket" alt="Latest Stable Version"></a>
<img src="https://img.shields.io/badge/PHP-8.0.2%2B-777BB4" alt="PHP 8.0.2+">
<img src="https://img.shields.io/badge/Laravel-9%2B-FF2D20" alt="Laravel 9+">
<img src="https://img.shields.io/badge/Windows-Native%20Transport-0078D4" alt="Native transport on Windows">
<img src="https://img.shields.io/badge/Swoole-Optional-25A162" alt="Swoole is optional">
</p>

[简体中文](README.md) | [English](README.en.md)

Laravel WebSocket server with Swoole and native PHP transports.

## Related packages

- [Giorgio IM](https://github.com/m-finder/giorgio-im): Vue 3 + TypeScript instant messaging component
- [Giorgio IM Socket](https://packagist.org/packages/wu/giorgio-im-socket): Laravel instant messaging backend
- [Giorgio Socket](https://packagist.org/packages/wu/giorgio-socket): Laravel WebSocket infrastructure package

## Install

Requirements: PHP 8.0.2+, Laravel 9+ and Redis. Direct TLS requires OpenSSL.

```bash
composer require wu/giorgio-socket
php artisan vendor:publish --provider="GiorgioSocket\Providers\SocketServiceProvider" --tag=socket-config
```

## Configure

```dotenv
SOCKET_HOST=127.0.0.1
SOCKET_PORT=9501
SOCKET_DRIVER=auto
SOCKET_PREFIX=giorgio-socket:9501:
SOCKET_PUSH_URL=http://127.0.0.1:9501/push
SOCKET_PUSH_TOKEN=replace-with-a-long-random-secret
```

`SOCKET_DRIVER` accepts three values:

- `auto`: Swoole on non-Windows systems when available, native PHP otherwise
- `swoole`: require Swoole
- `native`: force the PHP transport. Windows supports up to 60 concurrent connections

## Authentication

Set the authenticator in `config/socket.php`:

```php
'authenticate' => App\Services\SocketAuthenticator::class,
```

Without an authenticator, clients are rejected. Return a user ID to accept the connection. Return `null` to reject it. Later frames are authenticated again.

```php
<?php

namespace App\Services;

use GiorgioSocket\Services\ConnectionTicket;

class SocketAuthenticator
{
    public function __invoke(array $packet, bool $established = false): int|string|null
    {
        if (! is_string($packet['token'] ?? null)) {
            return null;
        }

        $claims = app(ConnectionTicket::class)->claims($packet['token'], $established);
        $id = $claims['user_id'] ?? null;

        return is_int($id) || is_string($id) ? $id : null;
    }
}
```

Issue tickets from your authenticated HTTP API. They expire after 60 seconds by default. Set `SOCKET_TICKET_TTL` to change it. The issuer and Socket server must use the same `APP_KEY`.

```php
$ticket = app(\GiorgioSocket\Services\ConnectionTicket::class)->issue([
    'user_id' => $request->user()->getKey(),
]);
```

## Connect

Start the server:

```bash
php artisan socket:start
```

Send the first frame from the client:

```js
const ws = new WebSocket('ws://127.0.0.1:9501');
ws.onopen = () => ws.send(JSON.stringify({ type: 'connect', token: ticket }));
ws.onmessage = ({ data }) => console.log(JSON.parse(data));
```

| Packet | Action |
| --- | --- |
| `connect` | Verify the ticket and return `connected` with `user_id` |
| `ping` | Re-authenticate and return `pong` |
| `close` | Close the connection |
| `message` | Rejected by default. Send business messages through the application API |

Send `ping` every 25 seconds. The server disconnects after 90 seconds without a client frame. Swoole checks every 30 seconds. The default frame limit is 65536 bytes.

## Push

The server exposes `POST /push` for server-side delivery. It uses Bearer authentication.

```php
$client = app(config('socket.handlers.client'));
$client->handle((object) [
    'to' => $userId,
    'message' => ['type' => 'updated', 'id' => 123],
]);
```

Set `to` to a user ID. Set `to: 'all'` to target online users. Use it for announcements, alerts or state updates. The application owns the admin UI and permissions.

All connections for the user receive the message. If every send fails, the message enters the Redis offline queue. The default limit is 100 messages per user with a seven-day TTL.

With the Laravel queue:

```php
GiorgioSocket\Events\SocketEvent::dispatch($userId, $payload);
```

Run a worker for asynchronous queues:

```bash
php artisan queue:work --queue=socket-listener
```

## Durable events

Publish the migration when clients need catch-up after reconnecting:

```bash
php artisan vendor:publish --provider="GiorgioSocket\Providers\SocketServiceProvider" --tag=socket-migrations
php artisan migrate
```

The default table is `socket_events`. Change it with `SOCKET_EVENTS_TABLE`. Write events in the same transaction as the related application data:

```php
use GiorgioSocket\Services\EventStore;

app(EventStore::class)->append([$userId], 'updated', ['id' => 123]);
$page = app(EventStore::class)->after($userId, $cursor);
```

Publish events:

```bash
php artisan socket:events
php artisan socket:events --once
```

Authenticated WebSocket connections can replay missed events:

```json
{"type":"sync","after":80,"request_id":1}
```

The server returns `{"type":"events","request_id":1,"events":[...],"cursor":90,"hasMore":false}` for the authenticated recipient only, ignoring client-supplied user IDs. Continue from the returned cursor while `hasMore=true` and deduplicate by event ID. No HTTP polling is required. Set `socket.events.reader` to use an application reader with business transaction locks.

Failed events remain pending. Clients should deduplicate by event ID. Cleanup removes expired published events:

```bash
php artisan socket:prune-events
php artisan socket:prune-events --hours=24
```

Run cleanup from Laravel Scheduler or cron. Unpublished events are kept.

## TLS

Use WSS and HTTPS in public deployments. Keep `/push` private.

```dotenv
SOCKET_TLS_ENABLED=true
SOCKET_TLS_CERT_FILE=/absolute/path/fullchain.pem
SOCKET_TLS_KEY_FILE=/absolute/path/privkey.pem
SOCKET_PUSH_URL=https://socket-internal.example.com/push
# SOCKET_PUSH_CA_FILE=/absolute/path/ca.pem
```

You can bind Socket to loopback and terminate TLS at a reverse proxy. Direct TLS enables TLS 1.2 and 1.3. Push requests verify certificates and reject redirects.

## Extension

`presence` is optional. It receives `true` on connection and on every heartbeat. It receives `false` when the last device disconnects. Use it to update online state or last activity. It fires repeatedly, so check for real state changes before broadcasting them.

`handlers` replaces the default handlers:

- `open`: connection established
- `message`: client frame
- `close`: connection closed
- `client`: server push

Interfaces are in `src/Services/Handlers/Interfaces/`. Laravel resolves the handlers.

Restart `socket:start` and `socket:events` after changing code, configuration or secrets.

## Licence

[MIT](LICENSE)

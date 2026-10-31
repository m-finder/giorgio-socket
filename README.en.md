# Giorgio Socket

[简体中文](README.md) | [English](README.en.md)

A Swoole WebSocket server for Laravel, with authentication hooks, heartbeats, multiple connections per user, offline delivery, TLS and an optional database event inbox with push retries.

## Requirements and installation

Requires PHP 8.0.2+, Laravel 9+ and the Swoole extension. Redis is required for the connection registry; use Laravel's Predis or PhpRedis connection. The PHP CLI used for Artisan must also load the Swoole extension.

```bash
composer require wu/giorgio-socket
php artisan vendor:publish --provider="GiorgioSocket\Providers\SocketServiceProvider" --tag=socket-config
```

## Minimum configuration

```dotenv
SOCKET_HOST=127.0.0.1
SOCKET_PORT=9501
SOCKET_PREFIX=giorgio-socket:9501:
SOCKET_PUSH_URL=http://127.0.0.1:9501/push
SOCKET_PUSH_TOKEN=replace-with-an-independent-random-secret
SOCKET_TICKET_TTL=60
SOCKET_REQUIRE_TLS=true
SOCKET_ALLOW_INSECURE_LOOPBACK=true
```

Keep `SOCKET_PUSH_TOKEN` on trusted servers. Use distinct Redis prefixes and ports for separate instances. Configure application callbacks in `config/socket.php`:

```php
'authenticate' => App\Services\SocketAuthenticator::class,
'presence' => null, // Optional service class implementing the presence callback.
```

Create `app/Services/SocketAuthenticator.php` in your application. This minimal implementation authenticates encrypted connection tickets:

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

Return a verified user ID to accept or `null` to reject. Socket binds the identity returned by this callback. Subsequent frames call the authenticator again with `$established = true`, which skips only the connection ticket expiry check.

This example validates encrypted tickets only. To enforce logout, account suspension or session revocation, include your own session identifier in the ticket and check that session and user on every authenticator call. Return `null` to reject subsequent operations.

The presence callback is `__invoke(int|string $id, bool $online): void`. Connections and heartbeats invoke `true` to refresh liveness; closing the last connection invokes `false`. The application should detect actual transitions before issuing online notifications.

## Tickets and protocol

`ConnectionTicket` issues Laravel-encrypted tickets from an authenticated HTTP endpoint:

```php
use GiorgioSocket\Services\ConnectionTicket;

$ticket = app(ConnectionTicket::class)->issue([
    'user_id' => $request->user()->getKey(),
]);
// Return $ticket to the current user through your own authenticated route.
```

Tickets expire after 60 seconds by default. Configure `SOCKET_TICKET_TTL` or pass a lifetime to `issue($claims, $ttl)`. The issuer and Socket process must share the same Laravel `APP_KEY`. Additional claims belong to your application; the package does not require a particular authentication extension.

## Start and connect

Start the service from the Laravel application where this package is installed:

```bash
php artisan socket:start
```

The loopback configuration above accepts local connections at `ws://127.0.0.1:9501`. For public WSS connections, see “TLS and deployment” below. Obtain a ticket through your application's authenticated API and send it in the first frame:

```js
const ws = new WebSocket('wss://example.com/socket');
ws.onopen = () => ws.send(JSON.stringify({ type: 'connect', token: ticket }));
ws.onmessage = ({ data }) => {
  const packet = JSON.parse(data);
  // connected: identity confirmed; pong: heartbeat; system: server push; error: rejected operation.
};
// After connected, send { type: 'ping' } every 25 seconds. Obtain a fresh ticket on reconnect.
```

| Client frame | Server action |
| --- | --- |
| `connect` with `token` | Authenticate, return `connected` with `user_id` |
| `ping` | Revalidate credentials, refresh liveness, return `pong` |
| `close` | Close this connection |
| `message` | Rejected by default; use an authenticated application API with business authorisation |

Default heartbeat checks run every 30 seconds; idle connections close after 90 seconds. Close codes: 1008 for invalid credentials, 1009 for oversized packets and 1011 for internal failures. The default packet limit is 65536 bytes. Applications still handle reconnection, rate limits and business permissions.

## Server push

```php
use GiorgioSocket\Services\Handlers\Interfaces\ClientSendInterface;

/** @var ClientSendInterface $client */
$client = app(config('socket.handlers.client'));
$client->handle((object) ['to' => $userId, 'message' => ['type' => 'updated', 'id' => 123]]);
```

The client authenticates `POST /push` with the server secret. Browsers receive `{ type: 'system', data: ... }`. `to: 'all'` targets currently online users only; provide explicit user IDs for offline recipients.

Alternatively, dispatch through Laravel's event queue:

```php
use GiorgioSocket\Events\SocketEvent;

SocketEvent::dispatch($userId, $payload);
```

The `sync` queue driver runs inline. With an asynchronous driver, run:

```bash
php artisan queue:work --queue=socket-listener
```

Delivery targets every online connection for a user. If all attempts fail, the packet enters a Redis offline queue, bounded to 100 entries with a seven-day expiry by default. A successful connection write acknowledges delivery; this is separate from persistent message storage and client processing acknowledgements.

## Durable events and catch-up

Publish the optional migration:

```bash
php artisan vendor:publish --provider="GiorgioSocket\Providers\SocketServiceProvider" --tag=socket-migrations
php artisan migrate
```

The default table is `socket_events`, configurable with `SOCKET_EVENTS_TABLE`. An existing table must match the bundled migration schema, including the `id`, `user_id`, `type`, `data`, `created_at` and `published_at` columns and indexes.

```php
use GiorgioSocket\Services\EventStore;
use Illuminate\Support\Facades\DB;

DB::transaction(function () use ($userId) {
    // Save application changes in this transaction.
    app(EventStore::class)->append([$userId], 'updated', ['id' => 123]);
});

// Obtain the recipient from the authenticated identity, never from a client parameter.
$page = app(EventStore::class)->after($request->user()->getKey(), $cursor);
// events, cursor, hasMore; 100 entries per page by default.
```

While the Socket server is running, start a separate process to publish pending events:

```bash
php artisan socket:events
# Publish one batch and exit.
php artisan socket:events --once
```

`publishPending()` sets `published_at` only after a successful push. Failures remain pending. Delivery is at least once: consumers deduplicate by event ID. `after()` includes published events for cursor catch-up. The host application schedules cleanup; retention must cover its supported catch-up window.

### Event cleanup

```bash
# Keep published events for 168 hours by default, or override with 24 hours.
php artisan socket:prune-events
php artisan socket:prune-events --hours=24
```

The command uses `socket.events.table` and only deletes rows whose `published_at` is older than the retention window. Unpublished records are always kept. Batched `DELETE` operations preserve auto-increment IDs; no `TRUNCATE` is used. Applications can call `EventStore::prune($hours, $batchSize = 1000)` directly; both arguments must be positive integers.

`SOCKET_EVENTS_RETENTION_HOURS` defaults to `168`; `socket.events.prune_batch_size` defaults to `1000`. Add a task to the host's Laravel schedule:

```php
$schedule->command('socket:prune-events')->dailyAt('03:00')->withoutOverlapping();
```

The host must run `schedule:work` or execute `schedule:run` every minute through cron. The package does not register an automatic cleanup schedule. Events outside retention cannot be replayed; the application should reload its business snapshot.

## TLS and deployment

Use WSS for public browser connections and HTTPS for application APIs. Swoole can bind to loopback behind a TLS reverse proxy. Restrict `/push` to trusted services.

For native TLS:

```dotenv
SOCKET_TLS_ENABLED=true
SOCKET_TLS_CERT_FILE=/absolute/path/fullchain.pem
SOCKET_TLS_KEY_FILE=/absolute/path/privkey.pem
SOCKET_PUSH_URL=https://socket-internal.example.com/push
# Optional private PEM CA bundle; system CA verification is the default.
# SOCKET_PUSH_CA_FILE=/absolute/path/ca.pem
```

Startup validates readable, matching certificate and key files and enables TLS 1.2/1.3 only. The push client verifies certificates and rejects redirects. `SOCKET_REQUIRE_TLS=true` rejects public plaintext listeners and push URLs. Disable the loopback exception with `SOCKET_ALLOW_INSECURE_LOOPBACK=false`.

Apply `GiorgioSocket\Http\Middleware\RequireSecureTransport::class` to application HTTP routes. It reads `socket.require_tls` and `socket.allow_insecure_loopback` by default. Its HTTP loopback exception applies only in Laravel's `local` and `testing` environments and checks both the request host and remote address. Configure Laravel trusted proxies for the actual reverse proxy addresses.

## Common configuration

These options live in the published `config/socket.php`. Edit options without environment variable mappings directly in that file:

| Option | Default | Purpose |
| --- | --- | --- |
| `authenticate` | `null` | Authentication service class; authentication is rejected until configured |
| `presence` | `null` | Optional presence callback service class |
| `max_message_bytes` | `65536` | Maximum client frame size in bytes |
| `offline_limit` | `100` | Maximum offline queue entries per user |
| `offline_ttl` | `604800` | Offline queue expiry in seconds |
| `allow_client_messages` | `false` | Allow clients to send `message` frames directly |
| `handlers` | Bundled handlers | Replace open, frame, close or internal push handlers |

## Extension points and layout

| Path | Responsibility |
| --- | --- |
| `src/Services/ConnectionRegistry.php` | Connection mappings, multiple devices and bounded offline queues |
| `src/Services/ConnectionTicket.php` | Short-lived encrypted tickets |
| `src/Services/EventStore.php` | Database events, cursor reads and push acknowledgement |
| `src/Services/Handlers/` | Open, frame, close and internal push handlers |
| `src/Services/TransportSecurity.php` | Push URL and loopback checks |
| `src/Http/Middleware/` | HTTP TLS policy |
| `src/Console/Commands/` | Socket and event publisher commands |

Replace `socket.handlers` classes with implementations of their respective interfaces in `src/Services/Handlers/Interfaces/`. Handlers are resolved through Laravel's container and support dependency injection. Before enabling `allow_client_messages`, implement recipient authorisation and business restrictions in a custom frame handler.

Restart long-running `socket:start` and `socket:events` processes after changing code, configuration or secrets. Refresh cached configuration when applicable.

## Licence

MIT; see [LICENSE](LICENSE).

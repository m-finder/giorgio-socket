<?php

namespace Tests\Unit\Socket;

use GiorgioSocket\Services\ConnectionRegistry;
use GiorgioSocket\Services\EventStore;
use GiorgioSocket\Services\Handlers\SocketMessage;
use GiorgioSocket\Services\Native\Frame;
use GiorgioSocket\Services\Native\Server;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class EventSyncTest extends TestCase
{
    private ConnectionRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'socket.events.table' => 'socket_events', 'socket.events.reader' => null, 'socket.authenticate' => 'sync.auth']);
        DB::purge('sqlite');
        (require dirname(__DIR__).'/database/migrations/create_socket_events_table.php')->up();
        $this->app->instance('sync.auth', fn ($packet) => ($packet['token'] ?? '') === 'valid' ? 7 : null);
        $this->registry = Mockery::mock(ConnectionRegistry::class);
        $this->app->instance(ConnectionRegistry::class, $this->registry);
    }

    private function sync(array $packet, ?string $user = '7', string $token = 'valid'): array
    {
        $this->registry->shouldReceive('user')->once()->with(1)->andReturn($user);
        if ($user !== null) {
            $this->registry->shouldReceive('credentials')->once()->with(1)->andReturn(['token' => $token]);
        }
        $replies = [];
        $server = Mockery::mock(Server::class);
        $server->shouldReceive('push')->andReturnUsing(function ($fd, $data) use (&$replies) {
            $replies[] = json_decode($data, true, 512, JSON_THROW_ON_ERROR);

            return true;
        });
        $server->shouldReceive('disconnect')->andReturnUsing(function ($fd, $code) use (&$replies) {
            $replies[] = ['closed' => $code];

            return true;
        });
        app(SocketMessage::class)->handle($server, new Frame(1, json_encode(['type' => 'sync'] + $packet, JSON_THROW_ON_ERROR)));

        return $replies[0];
    }

    public function test_replay_is_paginated_and_uses_only_the_authenticated_recipient(): void
    {
        $store = app(EventStore::class);
        $store->append([8], 'private', ['content' => 'other user']);
        for ($index = 0; $index < 102; $index++) {
            $store->append([7], 'message', ['content' => 'message '.$index]);
        }
        $page = $this->sync(['after' => 0, 'request_id' => 1, 'user_id' => 8]);
        $this->assertSame('events', $page['type']);
        $this->assertSame(1, $page['request_id']);
        $this->assertCount(100, $page['events']);
        $this->assertSame(2, $page['events'][0]['id']);
        $this->assertTrue($page['hasMore']);
        $next = $this->sync(['after' => $page['cursor'], 'request_id' => 2]);
        $this->assertCount(2, $next['events']);
        $this->assertFalse($next['hasMore']);
        $this->assertSame(103, $next['cursor']);
    }

    public function test_replay_rejects_unauthenticated_or_revoked_connections_and_invalid_cursors(): void
    {
        $this->assertSame(['closed' => 1008], $this->sync(['after' => 0, 'request_id' => 1], null));
        $this->assertSame(['closed' => 1008], $this->sync(['after' => 0, 'request_id' => 1], '7', 'revoked'));
        foreach ([[-1, 1], ['0', 1], [0, 0], [0, null]] as [$after, $request]) {
            $this->assertSame('error', $this->sync(['after' => $after, 'request_id' => $request])['type']);
        }
    }

    public function test_replay_limits_frame_size_and_uses_the_application_reader(): void
    {
        config(['socket.events.reader' => 'sync.reader', 'socket.max_message_bytes' => 600]);
        $this->app->instance('sync.reader', function ($id, $cursor) {
            $this->assertSame('7', $id);
            $this->assertSame(10, $cursor);

            return ['events' => [
                ['id' => 11, 'type' => 'message', 'data' => ['content' => str_repeat('x', 300)]],
                ['id' => 12, 'type' => 'message', 'data' => ['content' => str_repeat('y', 300)]],
            ], 'cursor' => 12, 'hasMore' => false];
        });
        $page = $this->sync(['after' => 10, 'request_id' => 1]);
        $this->assertCount(1, $page['events']);
        $this->assertSame(11, $page['cursor']);
        $this->assertTrue($page['hasMore']);
        $this->assertLessThanOrEqual(600, strlen(json_encode($page)));
    }
}

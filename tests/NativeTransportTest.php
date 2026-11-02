<?php

namespace Tests\Unit\Socket;

use GiorgioSocket\Services\ConnectionRegistry;
use GiorgioSocket\Services\Handlers\SocketClose;
use GiorgioSocket\Services\Handlers\SocketMessage;
use GiorgioSocket\Services\Native\Frame;
use GiorgioSocket\Services\Native\Server;
use GiorgioSocket\Services\SocketService;
use Mockery;
use Tests\TestCase;

class NativeTransportTest extends TestCase
{
    private Server $server;

    private array $streams = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['socket.driver' => 'native', 'socket.host' => '127.0.0.1', 'socket.tls.enabled' => false, 'socket.max_message_bytes' => 65536]);
        $this->server = new Server('127.0.0.1', 0);
        $this->server->set(app(SocketService::class)->configuration()['settings']);
        $this->server->listen();
    }

    protected function tearDown(): void
    {
        $this->server->shutdown();
        foreach ($this->streams as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        parent::tearDown();
    }

    public function test_auto_prefers_a_usable_swoole_server_and_native_can_be_forced(): void
    {
        $service = app(SocketService::class);
        $this->assertSame('native', $service->configuration()['driver']);
        $this->assertNull($service->configuration()['socket_type']);
        config(['socket.driver' => 'auto']);
        $available = PHP_OS_FAMILY !== 'Windows' && extension_loaded('swoole') && class_exists(\Swoole\WebSocket\Server::class);
        $this->assertSame($available ? 'swoole' : 'native', $service->driver());
        config(['socket.driver' => 'swoole']);
        if ($available) {
            $this->assertSame('swoole', $service->driver());
        } else {
            $this->expectException(\RuntimeException::class);
            $service->driver();
        }
    }

    public function test_invalid_driver_fails_before_starting(): void
    {
        config(['socket.driver' => 'typo']);
        $this->expectException(\InvalidArgumentException::class);
        app(SocketService::class)->start();
    }

    public function test_native_listener_keeps_the_existing_tls_policy(): void
    {
        config(['socket.host' => '0.0.0.0', 'socket.require_tls' => true]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TLS');
        app(SocketService::class)->configuration();
    }

    public function test_split_handshake_pipelined_frames_fragmentation_and_ping_pong(): void
    {
        $frames = [];
        $closed = [];
        $this->server->on('message', function ($server, Frame $frame) use (&$frames) {
            $frames[] = $frame->data;
            $server->push($frame->fd, $frame->data);
        });
        $this->server->on('close', function ($server, $fd) use (&$closed) {
            $closed[] = $fd;
        });
        $stream = $this->connect();
        fwrite($stream, substr($this->handshake(), 0, 20));
        $this->pump();
        $this->assertSame('', $this->receive($stream));
        fwrite($stream, substr($this->handshake(), 20).$this->masked('hel', 1, false).$this->masked('hi', 9).$this->masked('lo', 0).$this->masked('second'));
        $wire = $this->receive($stream);
        $this->assertStringContainsString('HTTP/1.1 101 Switching Protocols', $wire);
        $this->assertStringContainsString('Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', $wire);
        $this->assertStringEndsWith("\x8a\x02hi\x81\x05hello\x81\x06second", $wire);
        $this->assertSame(['hello', 'second'], $frames);
        $this->assertSame([1], $this->server->getConnections());
        fwrite($stream, $this->masked(pack('n', 1000), 8));
        $this->assertSame("\x88\x02".pack('n', 1000), $this->receive($stream));
        $this->assertSame([1], $closed);
        $this->assertFalse($this->server->exist(1));
    }

    public function test_extended_length_payload_is_delivered_only_after_the_full_frame_arrives(): void
    {
        $received = [];
        $this->server->on('message', function ($server, $frame) use (&$received) {
            $received[] = $frame->data;
        });
        $stream = $this->websocket();
        $payload = str_repeat('x', 65536);
        $frame = $this->masked($payload);
        fwrite($stream, substr($frame, 0, 9));
        $this->pump();
        $this->assertSame([], $received);
        fwrite($stream, substr($frame, 9));
        $this->pump(30);
        $this->assertSame([$payload], $received);
    }

    public function test_unmasked_and_oversized_frames_are_rejected_with_wire_close_codes(): void
    {
        $stream = $this->websocket();
        fwrite($stream, "\x81\x01x");
        $this->assertSame(1002, unpack('n', substr($this->receive($stream), 2, 2))[1]);
        $stream = $this->websocket();
        fwrite($stream, "\x81\xff".pack('NN', 0, 65537));
        $this->assertSame(1009, unpack('n', substr($this->receive($stream), 2, 2))[1]);
        $stream = $this->websocket();
        fwrite($stream, $this->masked(str_repeat('a', 40000), 1, false).$this->masked(str_repeat('b', 30000), 0));
        $this->assertSame(1009, unpack('n', substr($this->receive($stream), 2, 2))[1]);
    }

    public function test_invalid_control_frames_continuations_and_utf8_are_rejected(): void
    {
        foreach ([[$this->masked('x', 9, false), 1002], [$this->masked('orphan', 0), 1002], [$this->masked("\xff"), 1007], [$this->masked(pack('n', 1005), 8), 1002], [$this->masked('x', 8), 1002]] as [$frame, $code]) {
            $stream = $this->websocket();
            fwrite($stream, $frame);
            $this->assertSame($code, unpack('n', substr($this->receive($stream), 2, 2))[1]);
        }
    }

    public function test_shared_handlers_authenticate_replay_offline_packets_handle_heartbeat_and_http_push(): void
    {
        config(['socket.push_token' => 'secret', 'socket.authenticate' => 'socket.native.auth', 'socket.log' => false]);
        $this->app->instance('socket.native.auth', fn ($packet) => ($packet['token'] ?? '') === 'valid' ? 7 : null);
        $registry = Mockery::mock(ConnectionRegistry::class);
        $registry->shouldReceive('user')->with(1)->twice()->andReturn(null, '7');
        $registry->shouldReceive('bind')->once()->with(1, '7', ['type' => 'connect', 'token' => 'valid', 'user_id' => 999]);
        $registry->shouldReceive('presence')->with('7', true)->twice();
        $registry->shouldReceive('pending')->with('7')->twice()->andReturn('offline', null);
        $registry->shouldReceive('acknowledge')->with('7')->once();
        $registry->shouldReceive('credentials')->with(1)->once()->andReturn(['token' => 'valid']);
        $registry->shouldReceive('connections')->with('7')->twice()->andReturn([1], []);
        $registry->shouldReceive('remove')->with(1)->once()->andReturn('7');
        $registry->shouldReceive('presence')->with('7', false)->once();
        $this->app->instance(ConnectionRegistry::class, $registry);
        $message = app(SocketMessage::class);
        $this->server->on('message', fn ($server, $frame) => $message->handle($server, $frame));
        $this->server->on('close', fn ($server, $fd) => app(SocketClose::class)->handle($server, $fd));
        $this->server->on('request', fn ($request, $response) => app(SocketService::class)->push($this->server, $message, $request, $response));
        $stream = $this->websocket();
        fwrite($stream, $this->masked('{"type":"connect","token":"valid","user_id":999}'));
        $reply = $this->receive($stream);
        $this->assertStringContainsString('{"type":"connected","user_id":7}', $reply);
        $this->assertStringEndsWith('offline', $reply);
        fwrite($stream, $this->masked('{"type":"ping"}'));
        $this->assertStringEndsWith('{"type":"pong"}', $this->receive($stream));
        $http = $this->connect();
        $body = '{"to":7,"message":{"text":"hello"}}';
        fwrite($http, "POST /push HTTP/1.1\r\nHost: localhost\r\nAuthorization: Bearer secret\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body);
        $this->assertStringContainsString('{"ok":true}', $this->receive($http));
        $this->assertStringEndsWith('{"type":"system","data":{"text":"hello"}}', $this->receive($stream));
        fclose($stream);
        $this->pump();
        $this->assertFalse($this->server->exist(1));
    }

    public function test_http_push_rejects_missing_secrets_and_invalid_http_framing(): void
    {
        config(['socket.push_token' => 'secret']);
        $this->server->on('request', fn ($request, $response) => app(SocketService::class)->push($this->server, app(SocketMessage::class), $request, $response));
        foreach ([
            ["POST /push HTTP/1.1\r\nHost: localhost\r\nContent-Length: 2\r\n\r\n{}", 401],
            ["POST /push HTTP/1.1\r\nHost: localhost\r\nContent-Length: 65537\r\n\r\n", 413],
            ["POST /push HTTP/1.1\r\nHost: localhost\r\nContent-Length: 1\r\nContent-Length: 2\r\n\r\n{}", 400],
            ["POST /push HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n", 400],
            ["GET / HTTP/1.1\r\nHost: localhost\r\nX-Large: ".str_repeat('x', 17000), 431],
            [str_replace('Version: 13', 'Version: 12', $this->handshake()), 426],
        ] as [$request, $status]) {
            $stream = $this->connect();
            fwrite($stream, $request);
            $this->assertStringStartsWith('HTTP/1.1 '.$status, $this->receive($stream));
        }
    }

    public function test_idle_connections_are_closed_and_cleanup_is_emitted_once(): void
    {
        $closed = [];
        $this->server->set(['heartbeat_idle_time' => 0]);
        $this->server->on('close', function ($server, $fd) use (&$closed) {
            $closed[] = $fd;
        });
        $this->websocket();
        $this->server->shutdown();
        $this->assertSame([1], $closed);
    }

    private function connect()
    {
        $stream = stream_socket_client('tcp://'.$this->server->address(), $errno, $error, 2);
        $this->assertIsResource($stream, $error);
        stream_set_blocking($stream, false);
        $this->streams[] = $stream;

        return $stream;
    }

    private function websocket()
    {
        $stream = $this->connect();
        fwrite($stream, $this->handshake());
        $this->assertStringContainsString('101 Switching Protocols', $this->receive($stream));

        return $stream;
    }

    private function handshake(): string
    {
        return "GET /socket HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\nConnection: keep-alive, Upgrade\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n\r\n";
    }

    private function masked(string $data, int $opcode = 1, bool $final = true): string
    {
        $length = strlen($data);
        $header = chr(($final ? 128 : 0) | $opcode);
        $header .= $length < 126 ? chr(128 | $length) : ($length <= 65535 ? chr(254).pack('n', $length) : chr(255).pack('NN', 0, $length));
        $mask = 'abcd';
        for ($i = 0; $i < $length; $i++) {
            $data[$i] = $data[$i] ^ $mask[$i % 4];
        }

        return $header.$mask.$data;
    }

    private function pump(int $iterations = 10): void
    {
        for ($i = 0; $i < $iterations; $i++) {
            $this->server->tick(0.002);
        }
    }

    private function receive($stream): string
    {
        $this->pump(30);

        return stream_get_contents($stream);
    }
}

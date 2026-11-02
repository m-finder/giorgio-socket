<?php

namespace GiorgioSocket\Services\Native;

/** Single-process WebSocket/HTTP transport using PHP streams; no Swoole namespace shims. */
class Server
{
    private const HEADER_LIMIT = 16384;

    private array $settings = [];

    private array $callbacks = [];

    private array $clients = [];

    private array $streamIds = [];

    private $listener = null;

    private int $nextId = 1;

    public function __construct(private string $host, private int $port) {}

    public function set(array $settings): void
    {
        $this->settings = $settings;
    }

    public function on(string $event, callable $callback): void
    {
        $this->callbacks[strtolower($event)] = $callback;
    }

    public function start(): void
    {
        $this->listen();
        try {
            while (is_resource($this->listener)) {
                $this->tick();
            }
        } finally {
            $this->shutdown();
        }
    }

    /** Bind separately from the event loop, allowing applications to manage its lifecycle. */
    public function listen(): void
    {
        if (is_resource($this->listener)) {
            throw new \LogicException('The native socket server is already listening.');
        }
        $ssl = [];
        if (isset($this->settings['ssl_cert_file'])) {
            $ssl = [
                'local_cert' => $this->settings['ssl_cert_file'],
                'local_pk' => $this->settings['ssl_key_file'],
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER,
                'disable_compression' => true,
            ];
        }
        $host = str_contains($this->host, ':') && $this->host[0] !== '[' ? '['.$this->host.']' : $this->host;
        $this->listener = @stream_socket_server('tcp://'.$host.':'.$this->port, $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create(['ssl' => $ssl]));
        if ($this->listener === false) {
            throw new \RuntimeException('Cannot start native socket server: '.$error.' ('.$errno.').');
        }
        stream_set_blocking($this->listener, false);
    }

    public function address(): string
    {
        return is_resource($this->listener) ? (string) stream_socket_get_name($this->listener, false) : '';
    }

    /** Process one batch of ready streams without requiring pcntl or the sockets extension. */
    public function tick(float $timeout = 1.0): void
    {
        if (! is_resource($this->listener)) {
            return;
        }
        $read = [$this->listener];
        $write = [];
        foreach ($this->clients as $client) {
            if (! $client['closing']) {
                $read[] = $client['stream'];
            }
            if ($client['output'] !== '') {
                $write[] = $client['stream'];
            }
        }
        $except = null;
        $seconds = (int) $timeout;
        $ready = @stream_select($read, $write, $except, $seconds, (int) (($timeout - $seconds) * 1000000));
        if ($ready === false) {
            throw new \RuntimeException('Native socket stream_select failed.');
        }
        foreach ($read as $stream) {
            if ($stream === $this->listener) {
                $this->accept();
            } elseif (isset($this->streamIds[(int) $stream])) {
                $fd = $this->streamIds[(int) $stream];
                try {
                    $this->read($fd);
                } catch (\Throwable $error) {
                    report($error);
                    $this->drop($fd);
                }
            }
        }
        foreach ($write as $stream) {
            if (isset($this->streamIds[(int) $stream])) {
                $this->flush($this->streamIds[(int) $stream]);
            }
        }
        $now = microtime(true);
        foreach ($this->clients as $fd => $client) {
            $timeout = $client['established'] && ! $client['closing'] ? ($this->settings['heartbeat_idle_time'] ?? 90) : 10;
            if ($now - $client['activity'] >= $timeout) {
                $this->drop($fd);
            }
        }
    }

    public function shutdown(): void
    {
        if (is_resource($this->listener)) {
            fclose($this->listener);
        }
        $this->listener = null;
        foreach (array_keys($this->clients) as $fd) {
            $this->drop($fd);
        }
    }

    public function exist(int $fd): bool
    {
        return isset($this->clients[$fd]);
    }

    public function isEstablished(int $fd): bool
    {
        return ($this->clients[$fd]['established'] ?? false) && ! $this->clients[$fd]['closing'];
    }

    public function getConnections(): array
    {
        return array_values(array_filter(array_keys($this->clients), fn ($fd) => $this->isEstablished($fd)));
    }

    public function push(int $fd, string $data, int $opcode = 1, int $flags = 1): bool
    {
        if (! $this->isEstablished($fd) || ! in_array($opcode, [1, 2, 9, 10], true) || ($opcode >= 8 && strlen($data) > 125)) {
            return false;
        }

        return $this->queue($fd, $this->encode($data, $opcode));
    }

    public function disconnect(int $fd, int $code = 1000, string $reason = ''): bool
    {
        if (! $this->isEstablished($fd)) {
            return false;
        }
        if (! $this->validCloseCode($code) || strlen($reason) > 123 || preg_match('//u', $reason) !== 1) {
            return false;
        }
        $sent = $this->queue($fd, $this->encode(pack('n', $code).$reason, 8));
        $this->finish($fd);

        return $sent;
    }

    public function close(int $fd, bool $reset = false): bool
    {
        if (! $this->exist($fd)) {
            return false;
        }
        $this->drop($fd);

        return true;
    }

    private function accept(): void
    {
        // Leave room for the listener within Windows' smaller select descriptor set.
        $limit = PHP_OS_FAMILY === 'Windows' ? 60 : 200;
        while (($stream = @stream_socket_accept($this->listener, 0)) !== false) {
            if (count($this->clients) >= $limit) {
                fclose($stream);

                continue;
            }
            stream_set_blocking($stream, false);
            stream_set_read_buffer($stream, 0);
            $fd = $this->nextId++;
            $this->streamIds[(int) $stream] = $fd;
            $this->clients[$fd] = [
                'stream' => $stream, 'input' => '', 'output' => '',
                'tls' => isset($this->settings['ssl_cert_file']),
                'established' => false, 'closing' => false,
                'activity' => microtime(true), 'fragment' => '', 'opcode' => null,
            ];
        }
    }

    private function read(int $fd): void
    {
        if ($this->clients[$fd]['tls']) {
            $result = @stream_socket_enable_crypto($this->clients[$fd]['stream'], true, STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER);
            if ($result === false) {
                $this->drop($fd);
            } elseif ($result === true) {
                $this->clients[$fd]['tls'] = false;
            }
            // Decrypted application data can already be buffered after the handshake.
            if ($result !== true) {
                return;
            }
        }
        $data = @fread($this->clients[$fd]['stream'], 8192);
        if ($data === false || ($data === '' && feof($this->clients[$fd]['stream']))) {
            $this->drop($fd);

            return;
        }
        if ($data === '') {
            return;
        }
        $this->clients[$fd]['input'] .= $data;
        if ($this->clients[$fd]['established']) {
            $this->frames($fd);
        } else {
            $this->http($fd);
        }
    }

    private function http(int $fd): void
    {
        $input = $this->clients[$fd]['input'];
        $end = strpos($input, "\r\n\r\n");
        if ($end === false) {
            if (strlen($input) > self::HEADER_LIMIT) {
                $this->httpError($fd, 431);
            }

            return;
        }
        if ($end > self::HEADER_LIMIT) {
            $this->httpError($fd, 431);

            return;
        }
        $lines = explode("\r\n", substr($input, 0, $end));
        if (! preg_match('~^(GET|POST) (/[^ \r\n]*) HTTP/1\.1$~D', array_shift($lines), $match)) {
            $this->httpError($fd, 400);

            return;
        }
        $headers = [];
        foreach ($lines as $line) {
            if (! preg_match('/^([!#$%&\'*+.^_`|~0-9A-Za-z-]+):[ \t]*([^\r\n]*)$/D', $line, $header)) {
                $this->httpError($fd, 400);

                return;
            }
            $name = strtolower($header[1]);
            if (isset($headers[$name])) {
                $this->httpError($fd, 400);

                return;
            }
            $headers[$name] = trim($header[2]);
        }
        if (empty($headers['host']) || isset($headers['transfer-encoding']) || ! preg_match('/^[0-9]+$/D', $headers['content-length'] ?? '0')) {
            $this->httpError($fd, 400);

            return;
        }
        $length = (int) ($headers['content-length'] ?? 0);
        if ($length > $this->maxMessageBytes()) {
            $this->httpError($fd, 413);

            return;
        }
        if (strlen($input) < $end + 4 + $length) {
            return;
        }
        $request = new Request($fd, $headers, ['request_method' => $match[1], 'request_uri' => explode('?', $match[2], 2)[0]], substr($input, $end + 4, $length));
        $this->clients[$fd]['input'] = substr($input, $end + 4 + $length);
        if (isset($headers['upgrade'])) {
            $key = base64_decode($headers['sec-websocket-key'] ?? '', true);
            if ($match[1] !== 'GET' || $length !== 0 || strtolower($headers['upgrade']) !== 'websocket' || ! in_array('upgrade', array_map('trim', explode(',', strtolower($headers['connection'] ?? ''))), true) || $key === false || strlen($key) !== 16) {
                $this->httpError($fd, 400);

                return;
            }
            if (($headers['sec-websocket-version'] ?? '') !== '13') {
                $this->httpError($fd, 426);

                return;
            }
            $accept = base64_encode(sha1($headers['sec-websocket-key'].'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            if (! $this->queue($fd, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: ".$accept."\r\n\r\n")) {
                return;
            }
            $this->clients[$fd]['established'] = true;
            $this->clients[$fd]['activity'] = microtime(true);
            $this->emit('open', $this, $request);
            if ($this->isEstablished($fd)) {
                $this->frames($fd);
            }

            return;
        }
        $response = new Response(function (string $data) use ($fd) {
            $this->queue($fd, $data);
            $this->finish($fd);
        });
        $this->emit('request', $request, $response);
        $response->end();
    }

    private function httpError(int $fd, int $status): void
    {
        $response = new Response(function (string $data) use ($fd) {
            $this->queue($fd, $data);
            $this->finish($fd);
        });
        $response->status($status);
        if ($status === 426) {
            $response->header('Sec-WebSocket-Version', '13');
        }
        $response->end();
    }

    private function frames(int $fd): void
    {
        while ($this->isEstablished($fd)) {
            $input = $this->clients[$fd]['input'];
            if (strlen($input) < 2) {
                return;
            }
            $first = ord($input[0]);
            $second = ord($input[1]);
            $opcode = $first & 15;
            $final = ($first & 128) !== 0;
            $length = $second & 127;
            $offset = 2;
            if (($first & 112) !== 0 || ($second & 128) === 0 || ! in_array($opcode, [0, 1, 2, 8, 9, 10], true) || ($opcode >= 8 && (! $final || $length > 125))) {
                $this->disconnect($fd, 1002, 'Invalid frame');

                return;
            }
            if ($length === 126) {
                if (strlen($input) < 4) {
                    return;
                }
                $length = unpack('n', substr($input, 2, 2))[1];
                $offset = 4;
                if ($length < 126) {
                    $this->disconnect($fd, 1002, 'Invalid frame length');

                    return;
                }
            } elseif ($length === 127) {
                if (strlen($input) < 10) {
                    return;
                }
                $parts = unpack('Nhigh/Nlow', substr($input, 2, 8));
                if (($parts['high'] & 0x80000000) !== 0 || ($parts['high'] === 0 && $parts['low'] < 65536)) {
                    $this->disconnect($fd, 1002, 'Invalid frame length');

                    return;
                }
                // Refuse oversized lengths before allocating or waiting for the payload.
                if ($parts['high'] !== 0 || $parts['low'] > $this->maxMessageBytes()) {
                    $this->disconnect($fd, 1009, 'Message too large');

                    return;
                }
                $length = $parts['low'];
                $offset = 10;
            }
            if ($opcode < 8 && $length + strlen($this->clients[$fd]['fragment']) > $this->maxMessageBytes()) {
                $this->disconnect($fd, 1009, 'Message too large');

                return;
            }
            if (strlen($input) < $offset + 4 + $length) {
                return;
            }
            $mask = substr($input, $offset, 4);
            $payload = substr($input, $offset + 4, $length);
            $this->clients[$fd]['input'] = substr($input, $offset + 4 + $length);
            for ($i = 0; $i < $length; $i++) {
                $payload[$i] = $payload[$i] ^ $mask[$i % 4];
            }
            $this->clients[$fd]['activity'] = microtime(true);
            if ($opcode === 8) {
                $code = $length >= 2 ? unpack('n', substr($payload, 0, 2))[1] : 1000;
                if ($length === 1 || ! $this->validCloseCode($code)) {
                    $this->disconnect($fd, 1002, 'Invalid close frame');
                } elseif (preg_match('//u', substr($payload, 2)) !== 1) {
                    $this->disconnect($fd, 1007, 'Invalid UTF-8');
                } else {
                    $this->queue($fd, $this->encode($payload, 8));
                    $this->finish($fd);
                }

                return;
            }
            if ($opcode === 9) {
                $this->push($fd, $payload, 10);

                continue;
            }
            if ($opcode === 10) {
                continue;
            }
            $fragmentOpcode = $this->clients[$fd]['opcode'];
            if (($opcode === 0 && $fragmentOpcode === null) || ($opcode !== 0 && $fragmentOpcode !== null)) {
                $this->disconnect($fd, 1002, 'Invalid continuation');

                return;
            }
            if ($opcode === 0) {
                $payload = $this->clients[$fd]['fragment'].$payload;
                $opcode = $fragmentOpcode;
            }
            if (! $final) {
                $this->clients[$fd]['fragment'] = $payload;
                $this->clients[$fd]['opcode'] = $opcode;

                continue;
            }
            $this->clients[$fd]['fragment'] = '';
            $this->clients[$fd]['opcode'] = null;
            if ($opcode === 1 && preg_match('//u', $payload) !== 1) {
                $this->disconnect($fd, 1007, 'Invalid UTF-8');

                return;
            }
            $this->emit('message', $this, new Frame($fd, $payload, $opcode));
        }
    }

    private function maxMessageBytes(): int
    {
        return max(1, (int) ($this->settings['package_max_length'] ?? 65536));
    }

    private function validCloseCode(int $code): bool
    {
        return in_array($code, [1000, 1001, 1002, 1003, 1007, 1008, 1009, 1010, 1011, 1012, 1013, 1014], true) || ($code >= 3000 && $code <= 4999);
    }

    private function encode(string $data, int $opcode): string
    {
        $length = strlen($data);
        $header = chr(128 | $opcode);
        if ($length < 126) {
            $header .= chr($length);
        } elseif ($length <= 65535) {
            $header .= chr(126).pack('n', $length);
        } else {
            $header .= chr(127).pack('NN', 0, $length);
        }

        return $header.$data;
    }

    /** Bound each slow client's output; retain unsent bytes after partial writes. */
    private function queue(int $fd, string $data): bool
    {
        if (! isset($this->clients[$fd]) || $this->clients[$fd]['closing']) {
            return false;
        }
        if (strlen($this->clients[$fd]['output']) + strlen($data) > max(1048576, $this->maxMessageBytes() * 4)) {
            $this->drop($fd);

            return false;
        }
        $this->clients[$fd]['output'] .= $data;

        return $this->flush($fd);
    }

    private function flush(int $fd): bool
    {
        $output = $this->clients[$fd]['output'];
        if ($output !== '') {
            $written = @fwrite($this->clients[$fd]['stream'], $output);
            if ($written === false || ($written === 0 && feof($this->clients[$fd]['stream']))) {
                $this->drop($fd);

                return false;
            }
            $this->clients[$fd]['output'] = substr($output, $written);
        }
        if ($this->clients[$fd]['closing'] && $this->clients[$fd]['output'] === '') {
            $this->drop($fd);
        }

        return true;
    }

    private function finish(int $fd): void
    {
        if (isset($this->clients[$fd])) {
            $this->clients[$fd]['closing'] = true;
            $this->clients[$fd]['activity'] = microtime(true);
            $this->flush($fd);
        }
    }

    private function drop(int $fd): void
    {
        if (! isset($this->clients[$fd])) {
            return;
        }
        $client = $this->clients[$fd];
        unset($this->clients[$fd], $this->streamIds[(int) $client['stream']]);
        fclose($client['stream']);
        if ($client['established']) {
            try {
                $this->emit('close', $this, $fd);
            } catch (\Throwable $error) {
                report($error);
            }
        }
    }

    private function emit(string $event, ...$arguments): void
    {
        if (isset($this->callbacks[$event])) {
            ($this->callbacks[$event])(...$arguments);
        }
    }
}

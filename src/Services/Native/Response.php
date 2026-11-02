<?php

namespace GiorgioSocket\Services\Native;

class Response
{
    private array $headers = [];

    private int $statusCode = 200;

    private bool $ended = false;

    public function __construct(private \Closure $sender) {}

    public function header(string $name, string $value): void
    {
        if (preg_match('/[\r\n]/', $name.$value)) {
            throw new \InvalidArgumentException('Invalid HTTP response header.');
        }
        $this->headers[$name] = $value;
    }

    public function status(int $status): void
    {
        $this->statusCode = $status;
    }

    public function end(string $body = ''): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;
        $reasons = [200 => 'OK', 400 => 'Bad Request', 401 => 'Unauthorized', 404 => 'Not Found', 413 => 'Payload Too Large', 426 => 'Upgrade Required', 431 => 'Request Header Fields Too Large', 500 => 'Internal Server Error', 503 => 'Service Unavailable', 422 => 'Unprocessable Entity'];
        $response = 'HTTP/1.1 '.$this->statusCode.' '.($reasons[$this->statusCode] ?? 'Response')."\r\n";
        $this->headers['Content-Length'] = (string) strlen($body);
        $this->headers['Connection'] = 'close';
        foreach ($this->headers as $name => $value) {
            $response .= $name.': '.$value."\r\n";
        }
        ($this->sender)($response."\r\n".$body);
    }
}

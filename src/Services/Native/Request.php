<?php

namespace GiorgioSocket\Services\Native;

class Request
{
    public array $post = [];

    public function __construct(
        public int $fd,
        public array $header,
        public array $server,
        private string $body
    ) {
        if (strtolower(explode(';', $header['content-type'] ?? '')[0]) === 'application/x-www-form-urlencoded') {
            parse_str($body, $this->post);
        }
    }

    public function rawContent(): string
    {
        return $this->body;
    }
}

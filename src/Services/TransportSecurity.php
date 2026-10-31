<?php

namespace GiorgioSocket\Services;

class TransportSecurity
{
    public static function isLoopback(string $host): bool
    {
        return in_array(strtolower(trim($host, '[]')), ['localhost', '127.0.0.1', '::1'], true);
    }

    public static function assertPushUrl(string $url): void
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host'], $parts['scheme']) || ! in_array($parts['scheme'], ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('Invalid socket push URL. Use HTTP(S) without embedded credentials.');
        }
        if ($parts['scheme'] !== 'https' && config('socket.require_tls', true)
            && ! (config('socket.allow_insecure_loopback', true) && self::isLoopback($parts['host']))) {
            throw new \RuntimeException('Socket push requires TLS. Configure an HTTPS push URL.');
        }
    }
}

<?php

namespace GiorgioSocket\Http\Middleware;

use Closure;
use GiorgioSocket\Services\TransportSecurity;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSecureTransport
{
    /** The optional config namespace allows applications to use their own TLS policy. */
    public function handle(Request $request, Closure $next, string $configuration = 'socket'): Response
    {
        $local = config($configuration.'.allow_insecure_loopback', true) && app()->environment('local', 'testing')
            && TransportSecurity::isLoopback($request->getHost())
            && TransportSecurity::isLoopback((string) $request->server('REMOTE_ADDR'));
        if (config($configuration.'.require_tls', true) && ! $request->isSecure() && ! $local) {
            return response()->json(['message' => 'HTTPS is required. Insecure requests are not processed.'], 426);
        }

        return $next($request);
    }
}

<?php

namespace GiorgioSocket\Services\Handlers;

use GiorgioSocket\Services\Handlers\Interfaces\ClientSendInterface;
use GiorgioSocket\Services\TransportSecurity;
use Illuminate\Support\Facades\Http;

/** 受信任的服务端推送客户端，校验证书并禁止携带密钥跟随重定向。 */
class ClientSend implements ClientSendInterface
{
    public function handle(object $event): void
    {
        $secret = (string) config('socket.push_token', '');
        if ($secret === '') {
            throw new \RuntimeException('SOCKET_PUSH_TOKEN must be configured.');
        }
        $url = (string) config('socket.push_url', 'http://127.0.0.1:9501/push');
        TransportSecurity::assertPushUrl($url);
        $caFile = config('socket.push_ca_file');
        if ($caFile && (! is_string($caFile) || ! is_file($caFile) || ! is_readable($caFile))) {
            throw new \RuntimeException('Socket push CA file must be readable.');
        }
        $response = Http::withToken($secret)->withOptions(['verify' => $caFile ?: true, 'allow_redirects' => false])->timeout(5)->post($url, [
            'to' => $event->to, 'message' => $event->message,
        ])->throw();
        if (! $response->successful()) {
            throw new \RuntimeException('Socket push failed with HTTP '.$response->status());
        }
    }
}

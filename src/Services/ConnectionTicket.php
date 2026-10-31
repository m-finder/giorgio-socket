<?php

namespace GiorgioSocket\Services;

use Illuminate\Support\Facades\Crypt;

/** 短期连接票据只负责签发与解密，用户身份、权限和令牌吊销由应用鉴权器检查。 */
class ConnectionTicket
{
    /** 票据放在首个 WebSocket 帧中发送，避免凭据出现在 URL 和访问日志里。 */
    public function issue(array $claims, ?int $ttl = null): string
    {
        return Crypt::encryptString(json_encode(array_merge($claims, [
            'expires_at' => now()->addSeconds($ttl ?? (int) config('socket.ticket_ttl', 60))->timestamp,
        ]), JSON_THROW_ON_ERROR));
    }

    /** 已建立连接允许票据过期，但应用仍须在每次心跳检查原始登录令牌。 */
    public function claims(string $ticket, bool $established = false): ?array
    {
        try {
            $claims = json_decode(Crypt::decryptString($ticket), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($claims) || ! is_int($claims['expires_at'] ?? null)
                || (! $established && $claims['expires_at'] <= now()->timestamp)) {
                return null;
            }

            return $claims;
        } catch (\Throwable) {
            return null;
        }
    }
}

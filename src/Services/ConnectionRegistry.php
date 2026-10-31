<?php

namespace GiorgioSocket\Services;

use Illuminate\Support\Facades\Redis;

/** Redis 保存连接与用户的双向映射；同一用户可同时使用多个标签页或设备。 */
class ConnectionRegistry
{
    private function key(string $suffix): string
    {
        return config('socket.prefix', 'giorgio-socket:').$suffix;
    }

    /** 根据 Swoole 连接号获取已认证用户，不能用客户端声明的 user_id 代替。 */
    public function user(int $fd): ?string
    {
        $id = Redis::hget($this->key('connections'), (string) $fd);

        return $id === false || $id === null ? null : (string) $id;
    }

    /** 返回该用户的所有连接号；发送前仍需检查连接是否存活。 */
    public function connections(string $id): array
    {
        return array_map('intval', Redis::smembers($this->key('user:'.$id)));
    }

    /** 认证成功后绑定身份，并保存用于后续心跳重新验证的凭据。 */
    public function bind(int $fd, string $id, array $credentials = []): void
    {
        $this->remove($fd);
        Redis::hset($this->key('connections'), (string) $fd, $id);
        Redis::sadd($this->key('user:'.$id), (string) $fd);
        Redis::hset($this->key('credentials'), (string) $fd, json_encode($credentials, JSON_THROW_ON_ERROR));
    }

    /** 凭据只用于重新鉴权，不应返回客户端或写入日志。 */
    public function credentials(int $fd): array
    {
        return json_decode(Redis::hget($this->key('credentials'), (string) $fd) ?: '{}', true, 32, JSON_THROW_ON_ERROR);
    }

    /** 只移除当前连接，其他设备仍在线时不能把整个用户标记为离线。 */
    public function remove(int $fd): ?string
    {
        $id = $this->user($fd);
        if ($id !== null) {
            Redis::hdel($this->key('connections'), (string) $fd);
            Redis::hdel($this->key('credentials'), (string) $fd);
            Redis::srem($this->key('user:'.$id), (string) $fd);
        }

        return $id;
    }

    public function users(): array
    {
        return array_values(array_unique(Redis::hvals($this->key('connections'))));
    }

    /** 服务启动时清理上次进程的连接号；离线队列保留至 TTL 到期。 */
    public function reset(): void
    {
        foreach ($this->users() as $id) {
            Redis::del($this->key('user:'.$id));
        }
        Redis::del($this->key('connections'));
        Redis::del($this->key('credentials'));
    }

    /** 有容量和过期时间的兜底队列，不替代应用的持久消息数据库。 */
    public function enqueue(string $id, string $message): void
    {
        $limit = (int) config('socket.offline_limit', 100);
        if ($limit < 1) {
            return;
        }
        Redis::lpush($this->key('offline:'.$id), $message);
        Redis::ltrim($this->key('offline:'.$id), 0, $limit - 1);
        Redis::expire($this->key('offline:'.$id), (int) config('socket.offline_ttl', 604800));
    }

    /** 读取最早的待投递数据，成功发送后由 acknowledge 确认。 */
    public function pending(string $id): ?string
    {
        $message = Redis::lindex($this->key('offline:'.$id), -1);

        return $message === false || $message === null ? null : $message;
    }

    public function acknowledge(string $id): void
    {
        Redis::rpop($this->key('offline:'.$id));
    }

    /** 在线回调也用于刷新心跳；业务层应自行识别真正的上下线变化。 */
    public function presence(string $id, bool $online): void
    {
        if ($handler = config('socket.presence')) {
            app($handler)($id, $online);
        }
    }
}

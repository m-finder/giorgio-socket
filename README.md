# Giorgio Socket

[简体中文](README.md) | [English](README.en.md)

为 Laravel 提供基于 Swoole 的 WebSocket 服务。

本包负责连接鉴权、心跳、多设备投递、离线队列、TLS，以及可选的数据库事件收件箱与推送重试。

## 环境与安装

需要 PHP 8.0.2+、Laravel 9+ 和 Swoole 扩展。

连接注册表需要 Redis，Laravel 可使用 Predis 或 PhpRedis。执行 Artisan 命令的 PHP CLI 也需要加载 Swoole 扩展。

```bash
composer require wu/giorgio-socket
php artisan vendor:publish --provider="GiorgioSocket\Providers\SocketServiceProvider" --tag=socket-config
```

## 最小配置

```dotenv
SOCKET_HOST=127.0.0.1
SOCKET_PORT=9501
SOCKET_PREFIX=giorgio-socket:9501:
SOCKET_PUSH_URL=http://127.0.0.1:9501/push
SOCKET_PUSH_TOKEN=替换为独立随机长密钥
SOCKET_TICKET_TTL=60
SOCKET_REQUIRE_TLS=true
SOCKET_ALLOW_INSECURE_LOOPBACK=true
```

`SOCKET_PUSH_TOKEN` 只用于服务端请求，不发送给浏览器。不同实例使用不同 Redis 前缀和端口。发布配置后在 `config/socket.php` 中设置鉴权器和可选的在线回调：

```php
'authenticate' => App\Services\SocketAuthenticator::class,
'presence' => null, // 可选，填写实现在线回调的服务类。
```

在应用中创建 `app/Services/SocketAuthenticator.php`，下面是基于连接票据的最小实现：

```php
<?php

namespace App\Services;

use GiorgioSocket\Services\ConnectionTicket;

class SocketAuthenticator
{
    public function __invoke(array $packet, bool $established = false): int|string|null
    {
        if (! is_string($packet['token'] ?? null)) {
            return null;
        }

        $claims = app(ConnectionTicket::class)->claims($packet['token'], $established);
        $id = $claims['user_id'] ?? null;

        return is_int($id) || is_string($id) ? $id : null;
    }
}
```

返回已验证的用户 ID 表示成功，返回 `null` 表示拒绝。Socket 使用鉴权器返回值绑定身份。后续报文会以 `$established = true` 重新调用鉴权器，此时只跳过连接票据的到期检查。

以上示例仅验证加密票据。需要注销、封禁或会话吊销立即生效时，请在票据中加入应用自己的会话标识，并在鉴权器每次调用时检查对应会话和用户是否仍然有效；返回 `null` 即可拒绝后续操作。

在线回调约定 `__invoke(int|string $id, bool $online): void`。连接及心跳都会调用 `true` 以刷新在线时间，最后一个连接关闭才调用 `false`；应用应识别真正的状态变化，避免每次心跳发送上线通知。

## 连接票据与协议

`ConnectionTicket` 提供 Laravel 加密票据。由已认证的 HTTP 接口签发：

```php
use GiorgioSocket\Services\ConnectionTicket;

$ticket = app(ConnectionTicket::class)->issue([
    'user_id' => $request->user()->getKey(),
]);
// 在应用自己的认证路由中将 $ticket 返回给当前用户。
```

票据默认有效期为 60 秒，可通过 `SOCKET_TICKET_TTL` 配置，或通过 `issue($claims, $ttl)` 单独指定。签发端和 Socket 进程必须使用相同的 Laravel `APP_KEY`。票据中的附加字段由应用自行定义，本包不绑定特定认证扩展。

## 启动与客户端连接

在安装本包的 Laravel 应用目录启动服务：

```bash
php artisan socket:start
```

按上面的回环地址配置，本地可连接 `ws://127.0.0.1:9501`；公网 WSS 配置见下文「TLS 与部署」。浏览器通过应用自己的认证接口获取票据，再放进首帧：

```js
const ws = new WebSocket('wss://example.com/socket');
ws.onopen = () => ws.send(JSON.stringify({ type: 'connect', token: ticket }));
ws.onmessage = ({ data }) => {
  const packet = JSON.parse(data);
  // connected：身份确认；pong：心跳；system：服务端推送；error：无效操作。
};
// 收到 connected 后每 25 秒发送 { type: 'ping' }，断线后获取新票据重连。
```

| 客户端帧 | 服务端行为 |
| --- | --- |
| `connect` + `token` | 验证后返回 `connected` + `user_id` |
| `ping` | 重新鉴权，刷新在线时间，返回 `pong` |
| `close` | 关闭当前连接 |
| `message` | 默认拒绝；应通过应用的认证 API 检查业务权限并发送 |

服务端默认每 30 秒检查心跳，90 秒无活动关闭连接。身份失效使用关闭码 1008，超大报文使用 1009，内部故障使用 1011。默认报文上限 65536 字节。应用仍需处理重连、限流和业务授权。

## 服务端推送

```php
use GiorgioSocket\Services\Handlers\Interfaces\ClientSendInterface;

/** @var ClientSendInterface $client */
$client = app(config('socket.handlers.client'));
$client->handle((object) ['to' => $userId, 'message' => ['type' => 'updated', 'id' => 123]]);
```

推送客户端对内部 `POST /push` 使用 Bearer 密钥。浏览器收到 `{ type: 'system', data: ... }`。`to: 'all'` 只覆盖当前在线用户；需要离线接收者时由应用提供明确的用户 ID。

也可通过 Laravel 事件交给队列：

```php
use GiorgioSocket\Events\SocketEvent;

SocketEvent::dispatch($userId, $payload);
```

队列驱动为 `sync` 时同步执行；使用异步驱动时运行：

```bash
php artisan queue:work --queue=socket-listener
```

同一用户的所有在线连接都会收到数据；全部投递失败时进入 Redis 离线队列。默认最多 100 条，保留 7 天。该队列按成功写入连接确认，不能替代持久消息记录或客户端处理确认。

## 持久事件与补取

需要可靠事件记录时发布可选迁移：

```bash
php artisan vendor:publish --provider="GiorgioSocket\Providers\SocketServiceProvider" --tag=socket-migrations
php artisan migrate
```

默认表名为 `socket_events`，可通过 `SOCKET_EVENTS_TABLE` 配置。使用已有表时，其结构需兼容包内迁移定义的 `id`、`user_id`、`type`、`data`、`created_at` 和 `published_at` 字段及索引。

```php
use GiorgioSocket\Services\EventStore;
use Illuminate\Support\Facades\DB;

DB::transaction(function () use ($userId) {
    // 应用先保存自己的业务数据。
    app(EventStore::class)->append([$userId], 'updated', ['id' => 123]);
});

// HTTP 接口必须从已认证用户取得 ID，不能接受客户端指定收件人。
$page = app(EventStore::class)->after($request->user()->getKey(), $cursor);
// 返回 events、cursor、hasMore；默认每页 100 条。
```

在 Socket 服务运行期间，另起进程发布待推送事件：

```bash
php artisan socket:events
# 发布一批后退出
php artisan socket:events --once
```

`publishPending()` 成功推送后才设置 `published_at`；失败保留等待重试。投递是至少一次，客户端按事件 ID 去重。`after()` 包含已推送事件，断线后可继续用游标补取。事件清理由宿主调度，保留时间应覆盖应用允许的补取窗口。

### 事件清理

```bash
# 默认保留已发布事件 168 小时；可覆盖为 24 小时。
php artisan socket:prune-events
php artisan socket:prune-events --hours=24
```

命令使用 `socket.events.table`，只删除 `published_at` 早于保留窗口的记录，始终保留未发布事件。使用分批 `DELETE`，不执行 `TRUNCATE`、不重置自增 ID。`EventStore::prune($hours, $batchSize = 1000)` 可供应用直接调用，两个参数均须为正整数。

`SOCKET_EVENTS_RETENTION_HOURS` 默认 `168`；`socket.events.prune_batch_size` 默认 `1000`。在宿主的 Laravel 调度中添加：

```php
$schedule->command('socket:prune-events')->dailyAt('03:00')->withoutOverlapping();
```

宿主需运行 `schedule:work`，或通过 cron 每分钟执行 `schedule:run`。包本身不自动注册清理时间。超过保留窗口的事件无法继续补取，应用应重新加载业务快照。

## TLS 与部署

公网浏览器连接使用 WSS，HTTP API 使用 HTTPS。可让 Swoole 绑定回环地址并通过反向代理终止 TLS；`/push` 只向受信任服务开放。

原生 TLS：

```dotenv
SOCKET_TLS_ENABLED=true
SOCKET_TLS_CERT_FILE=/absolute/path/fullchain.pem
SOCKET_TLS_KEY_FILE=/absolute/path/privkey.pem
SOCKET_PUSH_URL=https://socket-internal.example.com/push
# 使用私有 CA 时指定 PEM 文件；默认验证系统 CA。
# SOCKET_PUSH_CA_FILE=/absolute/path/ca.pem
```

启动时检查证书、私钥及是否匹配，仅启用 TLS 1.2/1.3。推送客户端验证证书并禁止重定向。`SOCKET_REQUIRE_TLS=true` 默认拒绝公网明文监听和推送；回环例外可通过 `SOCKET_ALLOW_INSECURE_LOOPBACK=false` 关闭。

应用 HTTP 路由可使用 `GiorgioSocket\Http\Middleware\RequireSecureTransport::class`，默认读取 `socket.require_tls` 和 `socket.allow_insecure_loopback`。该中间件的 HTTP 回环例外只适用于 Laravel 的 `local`、`testing` 环境，并同时校验请求域名与来源地址。反向代理部署时按真实代理地址配置 Laravel 的可信代理。

## 常用配置

以下配置位于发布后的 `config/socket.php`，无环境变量映射的选项直接在该文件中修改：

| 配置项 | 默认值 | 说明 |
| --- | --- | --- |
| `authenticate` | `null` | 鉴权服务类；未配置时拒绝连接鉴权 |
| `presence` | `null` | 可选在线回调服务类 |
| `max_message_bytes` | `65536` | 单个客户端报文字节上限 |
| `offline_limit` | `100` | 每个用户离线队列的条数上限 |
| `offline_ttl` | `604800` | 离线队列过期时间，单位秒 |
| `allow_client_messages` | `false` | 是否允许客户端直接发送 `message` 帧 |
| `handlers` | 包内默认处理器 | 替换连接、报文、关闭或内部推送处理器 |

## 扩展与目录

| 位置 | 职责 |
| --- | --- |
| `src/Services/ConnectionRegistry.php` | 连接映射、多设备和有界离线队列 |
| `src/Services/ConnectionTicket.php` | 短期加密票据 |
| `src/Services/EventStore.php` | 数据库事件、游标读取和推送确认 |
| `src/Services/Handlers/` | 打开、报文、关闭和内部推送处理器 |
| `src/Services/TransportSecurity.php` | 推送地址与回环校验 |
| `src/Http/Middleware/` | HTTP TLS 策略 |
| `src/Console/Commands/` | Socket 与事件发布命令 |

通过 `socket.handlers` 配置替换实现相应接口的类；通过 Laravel 容器解析，支持依赖注入。接口位于 `src/Services/Handlers/Interfaces/`。开启 `allow_client_messages` 前，应在自定义报文处理器中实现接收者权限校验和业务限制。

服务使用常驻进程，修改代码、配置或密钥后应重新启动 `socket:start` 和 `socket:events`。配置缓存启用时先更新缓存。

## 许可

MIT，见 [LICENSE](LICENSE)。

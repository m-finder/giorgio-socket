# Giorgio Socket

<p align="center"><img src="https://m-finder.github.io/images/avatar.jpeg"></p>
<p align="center">
<img src="https://img.shields.io/badge/Author-m--finder-red" alt="Author">
<a href="https://packagist.org/packages/wu/giorgio-socket"><img src="https://img.shields.io/badge/License-MIT-green" alt="License"></a>
<a href="https://packagist.org/packages/wu/giorgio-socket"><img src="https://img.shields.io/packagist/v/wu/giorgio-socket" alt="Latest Stable Version"></a>
<img src="https://img.shields.io/badge/PHP-8.0.2%2B-777BB4" alt="PHP 8.0.2+">
<img src="https://img.shields.io/badge/Laravel-9%2B-FF2D20" alt="Laravel 9+">
<img src="https://img.shields.io/badge/Windows-Native%20Transport-0078D4" alt="Native transport on Windows">
<img src="https://img.shields.io/badge/Swoole-Optional-25A162" alt="Swoole is optional">
</p>

[简体中文](README.md) | [English](README.en.md)

Laravel WebSocket 服务，支持 Swoole 和 PHP 原生驱动

## 相关包

- [Giorgio IM](https://github.com/m-finder/giorgio-im)：Vue 3 + TypeScript 即时通讯组件
- [Giorgio IM Socket](https://packagist.org/packages/wu/giorgio-im-socket)：Laravel 即时通讯后端
- [Giorgio Socket](https://packagist.org/packages/wu/giorgio-socket)：Laravel WebSocket 底层服务

## 安装

环境要求：PHP 8.0.2+、Laravel 9+、Redis，直连 TLS 需要 OpenSSL

```bash
composer require wu/giorgio-socket
php artisan vendor:publish --provider="GiorgioSocket\Providers\SocketServiceProvider" --tag=socket-config
```

## 配置

```dotenv
SOCKET_HOST=127.0.0.1
SOCKET_PORT=9501
SOCKET_DRIVER=auto
SOCKET_PREFIX=giorgio-socket:9501:
SOCKET_PUSH_URL=http://127.0.0.1:9501/push
SOCKET_PUSH_TOKEN=replace-with-a-long-random-secret
```

`SOCKET_DRIVER`：`auto` 自动选择，`swoole` 强制使用 Swoole，`native` 强制使用 PHP 原生驱动。Windows 原生驱动最多 60 个并发连接

## 鉴权

鉴权器写在 `config/socket.php`：

```php
'authenticate' => App\Services\SocketAuthenticator::class,
```

未配置鉴权器时连接会被拒绝。返回用户 ID 表示通过，返回 `null` 表示拒绝，后续报文会再次鉴权

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

票据由应用认证接口签发，默认有效期 60 秒，可用 `SOCKET_TICKET_TTL` 修改，签发端和 Socket 服务必须使用同一个 `APP_KEY`

```php
$ticket = app(\GiorgioSocket\Services\ConnectionTicket::class)->issue([
    'user_id' => $request->user()->getKey(),
]);
```

## 连接

启动服务：

```bash
php artisan socket:start
```

客户端发送首帧：

```js
const ws = new WebSocket('ws://127.0.0.1:9501');
ws.onopen = () => ws.send(JSON.stringify({ type: 'connect', token: ticket }));
ws.onmessage = ({ data }) => console.log(JSON.parse(data));
```

| 报文 | 作用 |
| --- | --- |
| `connect` | 验证票据，返回 `connected` 和 `user_id` |
| `ping` | 重新鉴权，返回 `pong` |
| `close` | 关闭连接 |
| `message` | 默认拒绝，业务消息通过应用 API 发送 |

连接参数：`ping` 间隔 25 秒，空闲阈值 90 秒，Swoole 检查间隔 30 秒，单帧上限 65536 字节，空闲阈值统计所有客户端帧，不限于心跳

## 推送

推送接口：`POST /push`，鉴权方式：Bearer 密钥

```php
$client = app(config('socket.handlers.client'));
$client->handle((object) [
    'to' => $userId,
    'message' => ['type' => 'updated', 'id' => 123],
]);
```

`to` 填用户 ID，`to: 'all'` 发送给当前在线用户，适合公告、提醒和状态更新，管理员页面与权限由应用处理

消息发送到用户的所有在线连接，全部失败时写入 Redis 离线队列，默认每个用户最多 100 条，保留 7 天

使用 Laravel 队列时：

```php
GiorgioSocket\Events\SocketEvent::dispatch($userId, $payload);
```

异步队列需要运行：

```bash
php artisan queue:work --queue=socket-listener
```

## 持久事件

需要断线补取时发布迁移：

```bash
php artisan vendor:publish --provider="GiorgioSocket\Providers\SocketServiceProvider" --tag=socket-migrations
php artisan migrate
```

默认表：`socket_events`，表名配置：`SOCKET_EVENTS_TABLE`，事件应和业务数据放在同一个事务中

```php
use GiorgioSocket\Services\EventStore;

app(EventStore::class)->append([$userId], 'updated', ['id' => 123]);
$page = app(EventStore::class)->after($userId, $cursor);
```

发布事件：

```bash
php artisan socket:events
php artisan socket:events --once
```

已认证的 WebSocket 连接可补收事件：

```json
{"type":"sync","after":80,"request_id":1}
```

服务端返回 `{"type":"events","request_id":1,"events":[...],"cursor":90,"hasMore":false}`。只读取连接身份对应的事件，忽略客户端声明的用户 ID。`hasMore=true` 时用返回游标继续请求；客户端按事件 ID 去重。无需 HTTP 轮询。应用可用 `socket.events.reader` 指定带业务事务锁的读取服务。

失败事件会保留，客户端按事件 ID 去重，清理已发布事件：

```bash
php artisan socket:prune-events
php artisan socket:prune-events --hours=24
```

清理命令放进 Laravel Scheduler 或 cron，未发布事件不会被清理

## TLS

公网使用 WSS 和 HTTPS，`/push` 只开放给可信服务

```dotenv
SOCKET_TLS_ENABLED=true
SOCKET_TLS_CERT_FILE=/absolute/path/fullchain.pem
SOCKET_TLS_KEY_FILE=/absolute/path/privkey.pem
SOCKET_PUSH_URL=https://socket-internal.example.com/push
# SOCKET_PUSH_CA_FILE=/absolute/path/ca.pem
```

也可以让 Socket 监听回环地址，由反向代理终止 TLS。服务端只启用 TLS 1.2 和 TLS 1.3，推送请求会校验证书并拒绝重定向

## 扩展

`presence` 是可选在线回调，连接建立和每次心跳传入 `true`，最后一个设备断开传入 `false`，适合更新在线状态和最后活跃时间。回调会重复触发，广播状态变化前请自行判断状态是否改变

`handlers` 可替换默认处理器：

- `open`：连接建立
- `message`：客户端报文
- `close`：连接关闭
- `client`：服务端推送

处理器接口在 `src/Services/Handlers/Interfaces/`，由 Laravel 容器创建

修改代码、配置或密钥后重启 `socket:start` 和 `socket:events`

## 许可

[MIT](LICENSE)

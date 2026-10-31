<?php

namespace GiorgioSocket\Services;

use GiorgioSocket\Services\Handlers\Interfaces\ClientSendInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** 与业务无关的事件收件箱及 outbox；HTTP 游标补取和 WebSocket 推送使用相同事件 ID。 */
class EventStore
{
    private function query(): Builder
    {
        return DB::table(config('socket.events.table', 'socket_events'));
    }

    /** 在业务事务内调用，使数据变更和发给各接收者的事件一起提交。 */
    public function append(array $recipients, string $type, array $data): void
    {
        $payload = json_encode($data, JSON_THROW_ON_ERROR);
        foreach (array_unique($recipients) as $id) {
            $this->query()->insert(['user_id' => $id, 'type' => $type, 'data' => $payload]);
        }
    }

    public function latest(int|string $recipient): int
    {
        return (int) $this->query()->where('user_id', $recipient)->max('id');
    }

    /** 只读取指定接收者的事件；调用方必须从已认证身份提供 recipient。 */
    public function after(int|string $recipient, int $cursor, int $limit = 100): array
    {
        $events = $this->query()->where('user_id', $recipient)->where('id', '>', $cursor)
            ->orderBy('id')->limit($limit)->get();

        return [
            'events' => $events->map(fn ($event) => [
                'id' => $event->id, 'type' => $event->type, 'data' => json_decode($event->data, true, 512, JSON_THROW_ON_ERROR),
                'timestamp' => Carbon::parse($event->created_at)->getTimestampMs(),
            ])->all(),
            'cursor' => $events->last()?->id ?? $cursor, 'hasMore' => $events->count() === $limit,
        ];
    }

    /** 分批删除已确认推送且超过保留窗口的事件；保留待推送记录和自增游标。 */
    public function prune(int $hours, int $batchSize = 1000): int
    {
        if ($hours < 1 || $batchSize < 1) {
            throw new \InvalidArgumentException('Retention hours and batch size must be positive integers.');
        }
        $cutoff = now()->subHours($hours);
        $deleted = 0;
        do {
            $ids = $this->query()->where('published_at', '<', $cutoff)->orderBy('id')->limit($batchSize)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $deleted += $this->query()->whereIn('id', $ids)->where('published_at', '<', $cutoff)->delete();
        } while ($ids->count() === $batchSize);

        return $deleted;
    }

    /** 成功推送后才确认；异常保留事件等待重试。接收端应按事件 ID 去重。 */
    public function publishPending(ClientSendInterface $client, int $limit = 100): int
    {
        $published = 0;
        while ($published < $limit) {
            $sent = DB::transaction(function () use ($client) {
                $event = $this->query()->whereNull('published_at')->orderBy('id')->lockForUpdate()->first();
                if (! $event) {
                    return false;
                }
                $client->handle((object) ['to' => $event->user_id, 'message' => [
                    'id' => $event->id, 'type' => $event->type, 'data' => json_decode($event->data, true, 512, JSON_THROW_ON_ERROR),
                ]]);
                $this->query()->where('id', $event->id)->update(['published_at' => now()]);

                return true;
            }, 3);
            if (! $sent) {
                break;
            }
            $published++;
        }

        return $published;
    }
}

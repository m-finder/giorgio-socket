<?php

namespace GiorgioSocket\Console\Commands;

use GiorgioSocket\Services\EventStore;
use Illuminate\Console\Command;

/** 独立于 WebSocket 服务运行，重试数据库内尚未确认推送的事件。 */
class PublishEvents extends Command
{
    protected $signature = 'socket:events {--once : Publish one batch and exit}';

    protected $description = 'Publish durable events to Giorgio Socket';

    public function handle(EventStore $events): int
    {
        do {
            try {
                $events->publishPending(app(config('socket.handlers.client')));
            } catch (\Throwable $error) {
                report($error);
                $this->error('Socket push unavailable; events remain in the database for retry.');
                if ($this->option('once')) {
                    return self::FAILURE;
                }
            }
            if (! $this->option('once')) {
                usleep(250000);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}

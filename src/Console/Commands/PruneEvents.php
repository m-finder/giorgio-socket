<?php

namespace GiorgioSocket\Console\Commands;

use GiorgioSocket\Services\EventStore;
use Illuminate\Console\Command;

/** 由宿主调度执行，清理配置事件表中超过补取窗口的已发布记录。 */
class PruneEvents extends Command
{
    protected $signature = 'socket:prune-events {--hours= : Retain published events for this many hours}';

    protected $description = 'Prune expired published events from the configured Socket event table';

    public function handle(EventStore $events): int
    {
        $hours = filter_var($this->option('hours') ?? config('socket.events.retention_hours', 168), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $batchSize = filter_var(config('socket.events.prune_batch_size', 1000), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($hours === false || $batchSize === false) {
            $this->error('Retention hours and batch size must be positive integers.');

            return self::FAILURE;
        }
        $this->info('Deleted '.$events->prune($hours, $batchSize).' expired published events.');

        return self::SUCCESS;
    }
}

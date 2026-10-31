<?php

namespace GiorgioSocket\Providers;

use GiorgioSocket\Console\Commands\PruneEvents;
use GiorgioSocket\Console\Commands\PublishEvents;
use GiorgioSocket\Console\Commands\SocketServer;
use GiorgioSocket\Services\SocketService;
use Illuminate\Support\ServiceProvider;

class SocketServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/socket.php', 'socket');

        $this->app->singleton('socket', function () {
            return new SocketService();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->registerCommands();

        if ($this->app->runningInConsole()) {
            $this->publishing();
        }
    }

    /**
     * register commands
     */
    protected function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            SocketServer::class,
            PublishEvents::class,
            PruneEvents::class,
        ]);
    }

    /**
     * publishing
     */
    protected function publishing(): void
    {
        $this->publishes([__DIR__.'/../../config' => config_path()], 'socket-config');
        // 事件收件箱可选；已有兼容表的项目只需配置 socket.events.table。
        $this->publishes([
            __DIR__.'/../../database/migrations/create_socket_events_table.php' => database_path('migrations/'.date('Y_m_d_His').'_create_socket_events_table.php'),
        ], 'socket-migrations');
    }
}

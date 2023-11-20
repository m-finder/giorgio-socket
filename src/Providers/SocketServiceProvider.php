<?php

namespace GiorgioSocket\Providers;

use GiorgioSocket\Console\Commands\SocketServer;
use GiorgioSocket\Services\SocketService;
use Illuminate\Support\ServiceProvider;

class SocketServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/socket.php', 'socket');

        $this->app->singleton('socket', function () {
            return new SocketService();
        });
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->registerCommands();

        if ($this->app->runningInConsole()) {
            $this->publishing();
        }
    }

    protected function registerCommands(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            SocketServer::class,
        ]);
    }

    protected function publishing(): void
    {
        $this->publishes([__DIR__ . '/../../config' => config_path()]);
    }
}

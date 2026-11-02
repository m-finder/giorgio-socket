<?php

namespace GiorgioSocket\Console\Commands;

use GiorgioSocket\Facades\Socket;
use Illuminate\Console\Command;

class SocketServer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'socket:start';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start the WebSocket server (Swoole or native PHP)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->line('socket server is starting');

        Socket::start();

        return self::SUCCESS;
    }
}

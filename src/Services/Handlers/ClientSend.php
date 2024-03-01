<?php

namespace GiorgioSocket\Services\Handlers;

use GiorgioSocket\Services\Handlers\Interfaces\ClientSendInterface;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use function Swoole\Coroutine\run;

class ClientSend implements ClientSendInterface
{

    /**
     * http api send socket message
     * @param object $event
     * @return void
     */
    public function handle(object $event): void
    {
        if (config('socket.log')) {
            info('socket listener', [
                'to' => $event->to,
                'message' => $event->message,
            ]);
        }

        run(function () use ($event) {
            $client = new Client(config('socket.host', '0.0.0.0'), config('socket.port', 9501));
            $ret = $client->upgrade('/');

            if ($ret) {
                $client->push(json_encode([
                    'user_id' => config('socket.system_id'),
                    'user_name' => config('socket.system_name'),
                    'type' => 'system',
                    'to' => $event->to,
                    'data' => $event->message,
                ], JSON_UNESCAPED_UNICODE));

                Coroutine::sleep(0.1);
            }
        });
    }
}
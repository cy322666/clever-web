<?php

namespace App\Listeners;

use App\Services\Core\AlertService;
use Laravel\Horizon\Events\LongWaitDetected;

class SendHorizonLongWaitAlert
{
    public function handle(LongWaitDetected $event): void
    {
        $queue = "{$event->connection}:{$event->queue}";

        AlertService::warning(
            title: 'Horizon: очередь долго ждёт',
            message: "Очередь {$queue} ждёт {$event->seconds} сек.",
            context: [
                'connection' => $event->connection,
                'queue' => $event->queue,
                'wait_seconds' => $event->seconds,
            ],
            dedupeKey: 'horizon:long-wait:' . $queue,
            ttlSeconds: 300,
        );
    }
}

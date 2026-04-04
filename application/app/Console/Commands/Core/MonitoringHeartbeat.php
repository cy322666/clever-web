<?php

namespace App\Console\Commands\Core;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class MonitoringHeartbeat extends Command
{
    protected $signature = 'app:monitor-heartbeat';

    protected $description = 'Write scheduler heartbeat timestamp for monitoring';

    public function handle(): int
    {
        Cache::forever('monitoring:scheduler:last_heartbeat', now()->timestamp);

        return self::SUCCESS;
    }
}

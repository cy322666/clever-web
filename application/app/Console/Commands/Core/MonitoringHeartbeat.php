<?php

namespace App\Console\Commands\Core;

use App\Services\Core\MonitoringCache;
use Illuminate\Console\Command;

class MonitoringHeartbeat extends Command
{
    protected $signature = 'app:monitor-heartbeat';

    protected $description = 'Write scheduler heartbeat timestamp for monitoring';

    public function handle(): int
    {
        MonitoringCache::forever('monitoring:scheduler:last_heartbeat', now()->timestamp);

        return self::SUCCESS;
    }
}

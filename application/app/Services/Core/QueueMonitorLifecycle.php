<?php

namespace App\Services\Core;

use Croustibat\FilamentJobsMonitor\Models\QueueMonitor;
use Illuminate\Contracts\Queue\Job;
use Throwable;

final class QueueMonitorLifecycle
{
    public function failed(Job $job, ?Throwable $exception = null): void
    {
        $model = resolve(QueueMonitor::class);
        $connection = $model->getConnection();
        $attributes = [
            'failed' => true,
            'progress' => 100,
            'finished_at' => $connection->raw('COALESCE(finished_at, CURRENT_TIMESTAMP)'),
            'updated_at' => now(),
        ];
        if ($exception !== null) {
            $attributes['exception_message'] = mb_strcut($exception->getMessage(), 0, 65535);
        }

        // A terminal failure applies to every unsuccessful attempt of this UUID.
        // A later successful retry is a new monitor row and remains successful.
        $connection->table($model->getTable())
            ->where('job_id', $model::getJobId($job))
            ->where('attempt', '<=', $job->attempts())
            ->update($attributes);
    }
}

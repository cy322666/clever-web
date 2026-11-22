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
        $finishedAt = now();
        $attributes = [
            'failed' => true,
            'progress' => 100,
            'updated_at' => $finishedAt,
        ];
        if ($exception !== null) {
            $attributes['exception_message'] = mb_strcut($exception->getMessage(), 0, 65535);
        }

        // A terminal failure applies to every unsuccessful attempt of this UUID.
        // A later successful retry is a new monitor row and remains successful.
        $monitors = $connection->table($model->getTable())
            ->where('job_id', $model::getJobId($job))
            ->where('attempt', '<=', $job->attempts());
        $monitors->update($attributes);
        $monitors->whereNull('finished_at')->update(['finished_at' => $finishedAt]);
    }
}

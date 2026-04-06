<?php

namespace App\Observers;

use Croustibat\FilamentJobsMonitor\Models\FailedJob;
use Croustibat\FilamentJobsMonitor\Models\QueueMonitor;
use Illuminate\Support\Facades\Log;

class QueueMonitorObserver
{
    public function deleted(QueueMonitor $queueMonitor): void
    {
        $jobId = (string)($queueMonitor->job_id ?? '');

        if ($jobId === '') {
            return;
        }

        try {
            $query = FailedJob::query()->where('uuid', $jobId);

            if (ctype_digit($jobId)) {
                $query->orWhere('id', (int)$jobId);
            }

            $query->delete();
        } catch (\Throwable $e) {
            Log::warning('QueueMonitorObserver failed to delete source failed_jobs row.', [
                'queue_monitor_id' => $queueMonitor->id,
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

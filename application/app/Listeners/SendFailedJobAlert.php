<?php

namespace App\Listeners;

use App\Services\Core\AlertService;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class SendFailedJobAlert
{
    public function handle(JobFailed $event): void
    {
        $payload = $this->resolvePayload($event);

        $queue = (string)($event->job->getQueue() ?? '-');
        $connection = (string)($event->connectionName ?? '-');
        $jobName = (string)(
            Arr::get($payload, 'displayName')
            ?? Arr::get($payload, 'job')
            ?? $event->job->resolveName()
            ?? 'Unknown Job'
        );

        $jobUuid = (string)(Arr::get($payload, 'uuid') ?? '');
        $jobId = (string)($event->job->getJobId() ?? '');
        $attempts = (int)$event->job->attempts();
        $maxTries = Arr::get($payload, 'maxTries');
        $maxExceptions = Arr::get($payload, 'maxExceptions');
        $timeout = Arr::get($payload, 'timeout');

        $message = implode("\n", [
            "Упала job: {$jobName}",
            "Очередь: {$queue}",
            "Connection: {$connection}",
            "Job ID: " . ($jobId !== '' ? $jobId : '-'),
            "UUID: " . ($jobUuid !== '' ? $jobUuid : '-'),
        ]);

        $dedupeKeyBase = $jobUuid !== ''
            ? 'queue:job-failed:uuid:' . $jobUuid
            : ($jobId !== '' ? 'queue:job-failed:id:' . $jobId : 'queue:job-failed:' . sha1(
                    $jobName . '|' . $queue . '|' . $connection . '|' . Str::limit(
                        (string)$event->exception->getMessage(),
                        300,
                        ''
                    )
                ));

        AlertService::critical(
            title: 'Очередь: job failed',
            message: $message,
            context: [
                'attempts' => $attempts,
                'max_tries' => $maxTries,
                'max_exceptions' => $maxExceptions,
                'timeout' => $timeout,
                'exception' => Str::limit((string)$event->exception->getMessage(), 800, '...'),
            ],
            dedupeKey: $dedupeKeyBase,
            ttlSeconds: 86400,
        );
    }

    private function resolvePayload(JobFailed $event): array
    {
        try {
            $payload = $event->job->payload();

            return is_array($payload) ? $payload : [];
        } catch (\Throwable) {
            return [];
        }
    }
}

<?php

namespace App\Listeners;

use App\Services\Integrations\IntegrationErrorNotifier;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Events\Dispatcher;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use RuntimeException;
use Throwable;

final class AdminErrorSubscriber
{
    private ?JobProcessing $processing = null;

    private bool $capturing = false;

    private array $commands = [];

    private ?string $failedCommand = null;

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(MessageLogged::class, [$this, 'logged']);
        $events->listen(JobProcessing::class, [$this, 'processing']);
        $events->listen(JobExceptionOccurred::class, [$this, 'jobException']);
        $events->listen(CommandStarting::class, [$this, 'commandStarting']);
        $events->listen(CommandFinished::class, [$this, 'commandFinished']);
        foreach ([JobProcessed::class, Looping::class] as $event) {
            $events->listen($event, [$this, 'clearJob']);
        }
        foreach ([ScheduledTaskFailed::class, ScheduledTaskFinished::class, ScheduledBackgroundTaskFinished::class] as $event) {
            $events->listen($event, [$this, 'scheduled']);
        }
    }

    public function commandStarting(CommandStarting $event): void
    {
        // Command arguments may contain credentials or tinker source code.
        $this->failedCommand = null;
        $this->commands[] = preg_match('/^[a-z0-9][a-z0-9:_-]{0,100}$/iD', $event->command) ? $event->command : null;
    }

    public function commandFinished(CommandFinished $event): void
    {
        $command = array_pop($this->commands);
        // Laravel may log an exception after Symfony has emitted CommandFinished.
        $this->failedCommand = $event->exitCode !== 0 ? $command : null;
    }

    public function processing(JobProcessing $event): void
    {
        $this->processing = $event;
    }

    public function clearJob(mixed $event = null): void
    {
        $this->processing = null;
    }

    public function jobException(JobExceptionOccurred $event): void
    {
        // Keep the context for Laravel's subsequent exception log, until the next loop.
        $this->processing = new JobProcessing($event->connectionName, $event->job);
        app(IntegrationErrorNotifier::class)->queueFailed(new JobFailed($event->connectionName, $event->job, $event->exception));
    }

    public function logged(MessageLogged $event): void
    {
        if ($this->capturing || ! in_array($event->level, ['error', 'critical', 'alert', 'emergency'], true)) {
            return;
        }
        $notifier = app(IntegrationErrorNotifier::class);
        if (! $notifier->enabled()) {
            return;
        }
        $this->capturing = true;
        try {
            $exception = $event->context['exception'] ?? $event->message;
            if (! $exception instanceof Throwable) {
                $detail = $event->context['error'] ?? $event->message;
                $exception = new RuntimeException(is_string($detail) ? $detail : 'Error');
            }
            if ($this->processing) {
                $notifier->queueFailed(new JobFailed($this->processing->connectionName, $this->processing->job, $exception));

                return;
            }
            $userId = $this->id($event->context['user_id'] ?? $event->context['userId'] ?? null);
            $accountId = $this->id($event->context['account_id'] ?? null);
            $workflowId = $this->id($event->context['workflow_id'] ?? null);
            $source = $event->context['widget'] ?? $event->message;
            $widget = $notifier->widgetFromText(is_string($source) ? $source : '');
            if (app()->bound('request') && ! app()->runningInConsole()) {
                $notifier->httpFailed($exception, request(), $userId, $accountId, $widget);

                return;
            }
            $notifier->report($widget ?? 'platform', $exception->getMessage(), $userId, $accountId,
                ($command = $this->failedCommand ?: end($this->commands)) ? 'Команда: '.$command : 'Журнал приложения',
                $workflowId ?: null, exception: ($event->context['exception'] ?? null) instanceof Throwable ? $event->context['exception'] : null);

        } catch (Throwable) {
            // Reporting must never replace the original application error.
        } finally {
            $this->capturing = false;
        }
    }

    public function scheduled(ScheduledTaskFailed|ScheduledTaskFinished|ScheduledBackgroundTaskFinished $event): void
    {
        if (! $event instanceof ScheduledTaskFailed && (int) $event->task->exitCode === 0) {
            return;
        }
        $command = 'запланированная задача';
        if (preg_match('/artisan[\x27\x22]?\s+([a-z0-9:-]+)/i', (string) $event->task->command, $match)) {
            $command = $match[1];
        }
        app(IntegrationErrorNotifier::class)->report('platform',
            $event instanceof ScheduledTaskFailed ? $event->exception->getMessage() : 'Command failed (exit '.$event->task->exitCode.')',
            operation: 'Планировщик: '.$command, immediate: true,
            exception: $event instanceof ScheduledTaskFailed ? $event->exception : null);
    }

    private function id(mixed $value): int
    {
        return is_scalar($value) && preg_match('/^[1-9][0-9]{0,17}$/D', (string) $value) ? (int) $value : 0;
    }
}

<?php

namespace App\Listeners;

use App\Services\Integrations\IntegrationErrorNotifier;
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

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(MessageLogged::class, [$this, 'logged']);
        $events->listen(JobProcessing::class, [$this, 'processing']);
        $events->listen(JobExceptionOccurred::class, [$this, 'jobException']);
        foreach ([JobProcessed::class, Looping::class] as $event) {
            $events->listen($event, [$this, 'clearJob']);
        }
        foreach ([ScheduledTaskFailed::class, ScheduledTaskFinished::class, ScheduledBackgroundTaskFinished::class] as $event) {
            $events->listen($event, [$this, 'scheduled']);
        }
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
                'Ошибка обработки на платформе', $workflowId ?: null);
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
        if ($command === 'workflows:acceptance') {
            $context = app(\App\Services\Workflows\Testing\WorkflowAcceptanceDiagnostics::class)
                ->scheduledFailure($event instanceof ScheduledTaskFailed ? $event->exception : null);
            app(IntegrationErrorNotifier::class)->report('workflows', '',
                (int) $context['user_id'], (int) $context['account']['platform_account_id'],
                operation: 'Автотесты «Потоков»: workflows:acceptance',
                workflowId: (int) $context['source_workflow_id'], forceReason: $context['reason'], immediate: true,
                crmDomain: $context['account']['subdomain'].'.amocrm.ru');

            return;
        }
        app(IntegrationErrorNotifier::class)->report('platform',
            $event instanceof ScheduledTaskFailed ? $event->exception->getMessage() : 'Command failed',
            operation: 'Планировщик: '.$command, immediate: true);
    }

    private function id(mixed $value): int
    {
        return is_scalar($value) && preg_match('/^[1-9][0-9]{0,17}$/D', (string) $value) ? (int) $value : 0;
    }
}

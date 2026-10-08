<?php

namespace App\Services\Integrations;

use App\Jobs\Integrations\SendIntegrationErrorAlert;
use App\Models\Core\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class IntegrationErrorNotifier
{
    public const MODELS = [
        \App\Models\Integrations\YClients\Record::class => 'yclients',
        \App\Models\Integrations\YClients\MarketplaceInstallation::class => 'yclients',
        \App\Models\Integrations\ImportExcel\ImportRecord::class => 'import-excel',
        \App\Models\Integrations\Sqns\Visit::class => 'sqns',
        \App\Models\Integrations\Sqns\Setting::class => 'sqns',
        \App\Models\Integrations\Vetmanager\Visit::class => 'vetmanager',
        \App\Models\Integrations\Finder\Action::class => 'finder',
        \App\Models\Workflows\WorkflowRun::class => 'workflows',
    ];

    private const JOB_WIDGETS = [
        'YClients' => 'yclients', 'ImportExcel' => 'import-excel', 'Tilda' => 'tilda',
        'Distribution' => 'distribution', 'Sqns' => 'sqns', 'Vetmanager' => 'vetmanager',
        'Finder' => 'finder', 'Workflows' => 'workflows', 'FilamentWorkflows' => 'workflows',
    ];

    public function enabled(): bool
    {
        return (bool) config('alerts.enabled', true)
            && (bool) config('alerts.integration_errors.enabled', ! app()->environment('testing'))
            && filled(config('widget_lifecycle.telegram.token'))
            && filled(config('widget_lifecycle.telegram.chat_id'));
    }

    public function authFailed(Account $account, Throwable $exception): void
    {
        $this->report((string) $account->widget, $exception->getMessage(), (int) $account->user_id,
            (int) $account->id, 'Авторизация CRM', forceReason: 'auth', exception: $exception);
    }

    public function modelFailed(Model $model, ?string $fallbackError = null): void
    {
        $widget = self::MODELS[$model::class] ?? null;
        if ($widget === null || ! $this->enabled()) {
            return;
        }

        try {
            $userId = (int) $model->getAttribute('user_id');
            $accountId = (int) $model->getAttribute('account_id');
            if ($widget === 'finder') {
                $setting = $model->setting;
                $userId = (int) $setting?->user_id;
                $accountId = (int) $setting?->account_id;
            }
            $workflowId = $widget === 'workflows' ? (int) $model->getAttribute('workflow_id') : null;
            $error = $model->getAttribute('error_message') ?? $model->getAttribute('last_error') ?? $model->getAttribute('error');
            $error = filled($error) ? (string) $error : ($fallbackError ?? '');
            $this->report($widget, (string) $error, $userId, $accountId,
                $workflowId ? 'Сценарий #'.$workflowId.', запуск #'.$model->getKey() : 'Обработка записи #'.$model->getKey(),
                $workflowId);
        } catch (Throwable $exception) {
            $this->captureFailed($exception);
        }
    }

    public function queueFailed(JobFailed $event): bool
    {
        try {
            $payload = $event->job->payload();
            $name = (string) ($payload['displayName'] ?? $event->job->resolveName());
            if ($this->isAlertJob($name)) {
                return true;
            }
            $widget = null;
            foreach (self::JOB_WIDGETS as $namespace => $candidate) {
                if (str_contains($name, '\\'.$namespace.'\\')) {
                    $widget = $candidate;
                    break;
                }
            }
            $tags = [];
            foreach ((array) ($payload['tags'] ?? []) as $tag) {
                if (is_string($tag) && str_starts_with($tag, 'widget:') && substr($tag, 7) !== 'default') {
                    $widget ??= $this->widgetFromText(substr($tag, 7));
                }
                if (is_string($tag) && preg_match('/^([a-z_]+):([1-9][0-9]*)$/D', $tag, $match)) {
                    $tags[$match[1]] = (int) $match[2];
                }
            }
            $widget ??= 'platform';
            if (! $this->enabled()) {
                return false;
            }
            foreach ([
                'vetmanager_visit' => \App\Models\Integrations\Vetmanager\Visit::class,
                'sqns_visit_db' => \App\Models\Integrations\Sqns\Visit::class,
                'workflow_run' => \App\Models\Workflows\WorkflowRun::class,
            ] as $tag => $modelClass) {
                if (isset($tags[$tag]) && ($model = $modelClass::query()->find($tags[$tag]))) {
                    $this->modelFailed($model, $event->exception->getMessage());

                    return true;
                }
            }
            foreach ([
                'sqns_setting' => \App\Models\Integrations\Sqns\Setting::class,
                'import_setting' => \App\Models\Integrations\ImportExcel\ImportSetting::class,
                'finder_setting' => \App\Models\Integrations\Finder\Setting::class,
            ] as $tag => $modelClass) {
                if (isset($tags[$tag]) && ($setting = $modelClass::query()->find($tags[$tag]))) {
                    $tags['user'] = (int) $setting->user_id;
                    $tags['account'] = (int) ($setting->getAttribute('account_id') ?: ($tags['account'] ?? $tags['amo_account'] ?? 0));
                }
            }
            $this->report($widget, $event->exception->getMessage(), $tags['user'] ?? 0, $tags['account'] ?? $tags['amo_account'] ?? 0,
                'Фоновая задача: '.class_basename($name), exception: $event->exception);

            return true;
        } catch (Throwable $exception) {
            $this->captureFailed($exception);

            return false;
        }
    }

    public function httpFailed(Throwable $exception, Request $request, int $userId = 0, int $accountId = 0, ?string $widget = null): void
    {
        if ($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface && $exception->getStatusCode() < 500) {
            return;
        }
        try {
            $routeUser = $request->route('user');
            $userId = $routeUser instanceof User ? (int) $routeUser->id : ($userId ?: (int) $request->user()?->id);
            $route = $request->route();
            $routeName = $route instanceof \Illuminate\Routing\Route ? ($route->getName() ?: $route->uri()) : '';
            $widget ??= $this->widgetFromText((string) $routeName) ?? 'platform';
            $this->report($widget, $exception->getMessage(), $userId, $accountId,
                $routeName !== '' ? 'HTTP: '.$routeName : 'HTTP: маршрут не определён', exception: $exception);
        } catch (Throwable $error) {
            $this->captureFailed($error);
        }
    }

    public function report(string $widget, string $error, int $userId = 0, int $accountId = 0,
        string $operation = 'Обработка интеграции', ?int $workflowId = null, ?string $forceReason = null, bool $immediate = false, ?Throwable $exception = null): void
    {
        if (! $this->enabled()) {
            return;
        }
        try {
            [$account, $user] = $this->identity($widget, $userId, $accountId);
            $userId = (int) ($user?->id ?? $userId);
            $accountId = (int) ($account?->id ?? $accountId);
            [$reason, $description] = $this->reason($error, $forceReason);
            $diagnostic = IntegrationErrorDiagnostic::describe($error, $exception);
            if ($forceReason === null && $diagnostic['summary'] !== null) {
                $description = $diagnostic['summary'];
            } elseif ($reason === 'processing') {
                $description = $diagnostic['type'] ? 'Необработанная ошибка '.$diagnostic['type'].'.' : 'Сбой при выполнении операции.';
            }
            $keyParts = [$userId, $accountId, $widget, $reason, $workflowId ?? '', $widget === 'platform' ? $operation : ''];
            if ($reason === 'processing') {
                $keyParts[] = $diagnostic['signature'];
            }
            $key = hash('sha256', implode('|', $keyParts));
            $alertId = strtoupper(substr($key, 0, 12));
            $label = (string) config('widget_lifecycle.labels.'.$widget, match ($widget) {
                'platform' => 'Платформа', 'default' => 'Подключение amoCRM', 'tilda' => 'Тильда', default => $widget,
            });
            $domain = $account?->subdomain;
            if (filled($domain)) {
                $domain .= $account->zone === 'com' ? '.kommo.com' : '.amocrm.ru';
            }
            $baseUrl = rtrim((string) config('app.url'), '/');
            $message = implode("\n", array_filter([
                $widget === 'platform' ? 'Ошибка платформы' : 'Ошибка интеграции',
                $userId > 0 ? 'Клиент: '.($user?->email ?: 'ID '.$userId) : null,
                'Виджет: '.$label,
                filled($domain) ? 'CRM: '.$domain : null,
                'Где: '.$operation,
                'Причина: '.$description,
                $diagnostic['type'] ? 'Тип: '.$diagnostic['type'] : null,
                $diagnostic['location'] ? 'Код: '.$diagnostic['location'] : null,
                $diagnostic['endpoint'] ? 'Запрос: '.$diagnostic['endpoint'] : null,
                'ID ошибки: '.$alertId,
                'Время: '.now()->format('d.m.Y H:i:s'),
                $widget !== 'platform' || str_starts_with($operation, 'Фоновая задача:') || str_starts_with($operation, 'Очередь:')
                    ? 'Открыть: '.$baseUrl.($workflowId ? '/panel/workflows/'.$workflowId.'/edit' : '/panel/queue-monitors') : null,
            ], static fn ($line): bool => $line !== null));
            $cache = $this->cache();
            if ($cache && ($cache->has('integration-errors:sent:'.$key)
                || ! $cache->add('integration-errors:pending:'.$key, true, 300))) {
                return;
            }
            Log::info('Admin error diagnostic', [
                'alert_id' => $alertId, 'widget' => $widget, 'user_id' => $userId, 'account_id' => $accountId,
                'operation' => $operation, 'reason' => $reason, 'exception_class' => $diagnostic['type'],
                'source' => $diagnostic['location'], 'error_hash' => hash('sha256', $error),
            ]);
            // Only the safe summary enters the queue, never raw exceptions or payloads.
            $job = new SendIntegrationErrorAlert($message, $key);
            try {
                if ($immediate) {
                    $job->handle();
                } else {
                    Bus::dispatch($job);
                }
            } catch (Throwable) {
                if ($immediate) {
                    Bus::dispatch($job);
                } else {
                    $job->handle();
                }
            }
        } catch (Throwable $exception) {
            $this->captureFailed($exception);
        }
    }

    public function cooldown(): int
    {
        return max(60, (int) config('alerts.integration_errors.cooldown_seconds', 1800));
    }

    public function cache(): ?\Illuminate\Contracts\Cache\Repository
    {
        foreach (array_unique([config('alerts.cache_store', 'monitoring'), 'file']) as $store) {
            try {
                $cache = Cache::store($store);
                $cache->get('integration-errors:probe');

                return $cache;
            } catch (Throwable) {
                // A database outage must not disable the error notification path.
            }
        }

        return null;
    }

    public function widgetFromText(string $text): ?string
    {
        $text = mb_strtolower($text);
        $widgets = array_merge(array_keys((array) config('integrations.definitions', [])), array_values(self::JOB_WIDGETS));
        foreach (array_unique($widgets) as $widget) {
            if (preg_match('/(?<![a-z0-9])'.preg_quote($widget, '/').'(?![a-z0-9])/i', $text)) {
                return $widget;
            }
        }
        foreach (['importexcel' => 'import-excel', 'workflow' => 'workflows', 'amocrm.flow' => 'workflows', 'amocrm.excel' => 'import-excel'] as $name => $widget) {
            if (str_contains($text, $name)) {
                return $widget;
            }
        }

        return null;
    }

    public function isAlertJob(string $name): bool
    {
        foreach (['SendIntegrationErrorAlert', 'SendPlatformTechnicalAlert', 'SendAmoCrmOwnershipAlert'] as $job) {
            if (str_contains($name, $job)) {
                return true;
            }
        }

        return false;
    }

    private function identity(string $widget, int $userId, int $accountId): array
    {
        try {
            $account = $accountId > 0 ? Account::query()->find($accountId) : null;
            if ($account && $userId > 0 && (int) $account->user_id !== $userId) {
                $account = null;
            }
            if (! $account && $userId > 0) {
                $accounts = Account::query()->where('user_id', $userId)->where('active', true)->get();
                $account = $accounts->firstWhere('widget', $widget);
                if (! $account && $accounts->map(fn ($a) => $a->subdomain.'|'.$a->zone)->unique()->count() === 1) {
                    $account = $accounts->first();
                }
            }
            $user = User::query()->find($account?->user_id ?: $userId);

            return [$account, $user];
        } catch (Throwable) {
            return [null, null];
        }
    }

    private function reason(string $error, ?string $forced): array
    {
        $error = mb_strtolower($error);

        return match (true) {
            $forced === 'auth', preg_match('/\b401\b|invalid.grant|invalid.token|refresh.token|unauthorized/', $error) === 1 => ['auth', 'Не удалось подтвердить авторизацию. Проверьте подключение CRM или сервиса.'],
            preg_match('/\b403\b|forbidden|access.denied/', $error) === 1 => ['forbidden', 'Сервис отказал в доступе. Проверьте права подключения.'],
            preg_match('/\b429\b|too.many.requests|rate.limit/', $error) === 1 => ['rate_limit', 'Превышен лимит запросов внешнего сервиса.'],
            preg_match('/timeout|timed.out|curl error (6|7|28)|connection.refused|could not resolve/', $error) === 1 => ['connection', 'Не удалось связаться с сервисом или дождаться ответа.'],
            preg_match('/\b50[0-9]\b|service.unavailable|bad.gateway/', $error) === 1 => ['server', 'Сервис вернул серверную ошибку.'],
            preg_match('/\b404\b|not.found/', $error) === 1 => ['not_found', 'Не найдена запись или ресурс во внешнем сервисе.'],
            preg_match('/sqlstate|database|permission denied/', $error) === 1 => ['internal', 'Внутренняя ошибка платформы. Нужна проверка журнала ошибок.'],
            default => ['processing', 'Ошибка обработки данных или выполнения действия. Нужна проверка журнала ошибок.'],
        };
    }

    private function captureFailed(Throwable $exception): void
    {
        Log::warning('Integration error alert capture failed', ['exception_class' => $exception::class]);
    }
}

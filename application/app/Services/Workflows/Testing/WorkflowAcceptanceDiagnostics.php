<?php

namespace App\Services\Workflows\Testing;

use App\Models\User;
use App\Models\Workflows\Workflow;
use App\Services\Workflows\WorkflowConnectionAccess;
use RuntimeException;
use Throwable;

final class WorkflowAcceptanceDiagnostics
{
    // Only these authored messages may be forwarded to the administrative chat.
    public const REASONS = [
        'amo_disconnected' => 'Подключение amoCRM отключено или отсутствует. Подключите «Потоки» к amoCRM заново.',
        'amo_auth' => 'Ошибка авторизации в amoCRM: доступ отклонён или токен недействителен. Переподключите «Потоки».',
        'amo_forbidden' => 'amoCRM отказала в доступе (403). Проверьте права пользователя и интеграции.',
        'amo_config' => 'Ключи подключения не соответствуют виджету «Потоки». Проверьте настройки OAuth.',
        'amo_scope' => 'Подключён другой аккаунт amoCRM, не указанный в настройках автотестов. Запуск заблокирован.',
        'amo_not_technical' => 'Выбранный аккаунт amoCRM не является техническим. Автотесты с изменением данных заблокированы.',
        'amo_rate_limit' => 'amoCRM отклонила запросы из-за лимита (429).',
        'amo_connection' => 'Не удалось соединиться с amoCRM или дождаться ответа.',
        'amo_server' => 'amoCRM вернула серверную ошибку (5xx).',
        'acceptance_source' => 'Тестовый сценарий или его владелец не найден. Проверьте настройки автотестов.',
        'acceptance_storage' => 'Не удалось прочитать или сохранить отчёт автотестов. Проверьте права и место на диске.',
        'acceptance_database' => 'Ошибка подключения к базе данных при запуске автотестов.',
        'acceptance_cache' => 'Redis недоступен. Не удалось проверить блокировку запуска автотестов.',
        'acceptance_recovery' => 'Не подтверждено восстановление тестовых данных. Требуется проверка контрольной точки перед повторным запуском.',
        'acceptance_timeout' => 'Превышено время выполнения автотестов. Проверьте восстановление тестовых данных.',
        'acceptance_cases' => 'Проверки нод завершились с ошибками. Неуспешные проверки перечислены в отчёте автотестов.',
        'acceptance_delivery' => 'Telegram не подтвердил доставку отчёта автотестов. Отчёт сохранён на сервере.',
        'acceptance_no_report' => 'Процесс автотестов завершился без отчёта. Причина остановки не была сохранена.',
        'acceptance_process' => 'Процесс автотестов завершился с ошибкой. Техническая причина сохранена в отчёте.',
    ];

    public function context(int $workflowId, string $domain): array
    {
        $context = ['source_workflow_id' => $workflowId, 'user_id' => 0, 'owner_email' => null,
            'account' => ['subdomain' => $domain, 'platform_account_id' => 0]];
        try {
            $workflow = Workflow::withoutGlobalScopes()->whereNull('deleted_at')->find($workflowId);
            $user = $workflow ? User::find($workflow->user_id) : null;
            if ($user) {
                $context['user_id'] = (int) $user->id;
                $context['owner_email'] = $user->email;
                // Include disconnected slots: their owner is still known.
                $account = $user->accounts()->where('widget', 'workflows')->first();
                $context['account']['platform_account_id'] = (int) $account?->id;
            }
        } catch (Throwable) {
            // Preserve the original database/storage failure rather than masking it.
        }

        return $context;
    }

    public function assertReady(int $workflowId, string $domain): void
    {
        $workflow = Workflow::withoutGlobalScopes()->whereNull('deleted_at')->find($workflowId);
        $user = $workflow ? User::find($workflow->user_id) : null;
        if (! $user) throw new RuntimeException('Acceptance source workflow or owner is missing');
        $account = $user->accounts()->where('widget', 'workflows')->first();
        if (! $account || ! $account->active || blank($account->refresh_token) || blank($account->subdomain)) {
            throw new RuntimeException('amoCRM connection is disconnected');
        }
        if (! WorkflowConnectionAccess::isWidgetAccount($account)) {
            throw new RuntimeException('Workflow OAuth credentials do not match');
        }
        if ($account->subdomain !== $domain) throw new RuntimeException('Explicit account scope does not match');
    }

    public static function reason(string $error): string
    {
        $error = mb_strtolower($error);

        return match (true) {
            str_contains($error, 'disconnected'), str_contains($error, 'no connected account') => 'amo_disconnected',
            str_contains($error, 'oauth credentials do not match') => 'amo_config',
            str_contains($error, 'account scope does not match'), str_contains($error, 'account id does not match') => 'amo_scope',
            str_contains($error, 'only accepts a technical') => 'amo_not_technical',
            str_contains($error, 'source workflow or owner is missing') => 'acceptance_source',
            str_contains($error, 'процесс не сохранил отчёт') => 'acceptance_no_report',
            str_contains($error, 'redis') => 'acceptance_cache',
            preg_match('/sqlstate|database.connection/', $error) === 1 => 'acceptance_database',
            preg_match('/\b401\b|invalid.grant|invalid.token|unauthorized|refresh.token/', $error) === 1 => 'amo_auth',
            preg_match('/\b403\b|forbidden|access.denied/', $error) === 1 => 'amo_forbidden',
            preg_match('/\b429\b|too.many.requests/', $error) === 1 => 'amo_rate_limit',
            str_contains($error, 'превышено время прогона') => 'acceptance_timeout',
            preg_match('/timeout|timed.out|curl error (6|7|28)|could not resolve|connection.refused/', $error) === 1 => 'amo_connection',
            preg_match('/\b50[0-9]\b|bad.gateway|service.unavailable/', $error) === 1 => 'amo_server',
            preg_match('/permission denied|report directory|report file|disk|no space/', $error) === 1 => 'acceptance_storage',
            str_contains($error, 'database') => 'acceptance_database',
            preg_match('/recovery|checkpoint|восстановлен/', $error) === 1 => 'acceptance_recovery',
            default => 'acceptance_process',
        };
    }

    public static function reportReason(array $report): string
    {
        if (! empty($report['fatal_error'])) return self::reason((string) $report['fatal_error']);
        if (! empty($report['report_write_errors'])) return 'acceptance_storage';
        foreach ($report['cleanup'] ?? [] as $check) {
            if ($check === false || (is_array($check) && ($check['ok'] ?? null) === false)) return 'acceptance_recovery';
        }
        foreach ($report['cases'] ?? [] as $case) {
            if (is_array($case) && ($case['status'] ?? '') !== 'skipped'
                && (($case['status'] ?? '') !== 'passed' || ($case['verification']['passed'] ?? null) === false)) {
                $reason = self::reason((string) ($case['error'] ?? $case['result']['error'] ?? ''));
                return $reason === 'acceptance_process' ? 'acceptance_cases' : $reason;
            }
        }
        if (isset($report['notification']) && ! ($report['notification']['ok'] ?? false)) return 'acceptance_delivery';

        return 'acceptance_process';
    }

    public function scheduledFailure(?Throwable $exception = null): array
    {
        $workflowId = (int) config('workflow_acceptance.workflow_id');
        $domain = (string) config('workflow_acceptance.domain');
        $context = $this->context($workflowId, $domain);
        // Read only a just-finished report for this configured workflow. Never
        // attribute yesterday's failure or another tenant's report to this run.
        $paths = glob(rtrim((string) config('workflow_acceptance.report_directory'), '/').'/scheduled-*.json') ?: [];
        rsort($paths);
        foreach (array_slice($paths, 0, 3) as $path) {
            if (is_link($path) || ! is_readable($path) || filemtime($path) < time() - 300) continue;
            $report = json_decode(file_get_contents($path), true);
            if (! is_array($report) || (int) ($report['source_workflow_id'] ?? 0) !== $workflowId
                || ($report['account']['subdomain'] ?? '') !== $domain
                || empty($report['finished_at']) || strtotime($report['finished_at']) < time() - 300) continue;

            return $context + ['reason' => self::reportReason($report)];
        }
        try {
            $this->assertReady($workflowId, $domain);
        } catch (Throwable $error) {
            return $context + ['reason' => self::reason($error->getMessage())];
        }

        return $context + ['reason' => $exception ? self::reason($exception->getMessage()) : 'acceptance_no_report'];
    }
}

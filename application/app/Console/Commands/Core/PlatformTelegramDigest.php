<?php

namespace App\Console\Commands\Core;

use App\Models\Billing\SubscriptionInvoiceRequest;
use App\Models\Billing\WidgetSubscription;
use App\Services\Core\AlertService;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PlatformTelegramDigest extends Command
{
    protected $signature = 'platform:telegram-digest
        {--hours=24 : Период отчёта в часах}
        {--dry-run : Только вывести текст, без отправки в Telegram}';

    protected $description = 'Send platform analytics digest to Telegram';

    public function handle(): int
    {
        if (!config('alerts.enabled', true)) {
            $this->error('Алерты выключены: ALERTS_ENABLED=false.');

            return self::FAILURE;
        }

        if (!config('alerts.channels.telegram.enabled', true)) {
            $this->error('Telegram-алерты выключены: ALERTS_TG_ENABLED=false.');

            return self::FAILURE;
        }

        if (blank(config('alerts.channels.telegram.token')) || blank(config('alerts.channels.telegram.chat_id'))) {
            $this->error('Не настроены TELEGRAM_ALERTS_TOKEN / TELEGRAM_ALERTS_CHAT_ID.');

            return self::FAILURE;
        }

        $hours = max(1, min(168, (int)$this->option('hours')));
        $to = now();
        $from = $to->copy()->subHours($hours);

        $message = $this->buildMessage($from, $to, $hours);

        if ((bool)$this->option('dry-run')) {
            $this->line($message);

            return self::SUCCESS;
        }

        AlertService::info(
            title: 'Сводка платформы',
            message: $message,
            context: [
                'period_hours' => $hours,
                'from' => $from->toDateTimeString(),
                'to' => $to->toDateTimeString(),
            ],
            dedupeKey: 'platform:telegram-digest:' . $from->format('YmdH') . ':' . $to->format('YmdH'),
            ttlSeconds: max(3600, $hours * 3600 - 300),
        );

        $this->info('Сводка платформы отправлена в Telegram.');

        return self::SUCCESS;
    }

    private function buildMessage(Carbon $from, Carbon $to, int $hours): string
    {
        $lines = [
            'Сводка платформы',
            'Период: ' . $from->format('d.m H:i') . ' - ' . $to->format('d.m H:i') . " ({$hours} ч.)",
            '',
            'Клиенты и подключения',
            'Новые регистрации: ' . $this->countBetween('users', 'created_at', $from, $to),
            'Новые amoCRM-подключения: ' . $this->countAccountsBetween($from, $to),
            'Активные пользователи всего: ' . $this->countWhere('users', ['active' => true]),
            '',
            'Подписки и счета',
            'Активные подписки: ' . $this->countActiveSubscriptions(),
            'Новые подписки: ' . $this->countBetween('widget_subscriptions', 'created_at', $from, $to),
            'Просроченные/заблокированные: ' . $this->countInactiveSubscriptions(),
            'Новые заявки на счёт: ' . $this->countBetween('subscription_invoice_requests', 'created_at', $from, $to),
            'Заявки в работе: ' . $this->countOpenInvoiceRequests(),
            '',
            ...$this->workflowLines($from, $to),
            '',
            ...$this->queueLines($from, $to),
        ];

        return Str::limit(
            implode("\n", array_values(array_filter($lines, static fn($line): bool => $line !== null))),
            3800,
            "\n...обрезано",
        );
    }

    private function workflowLines(Carbon $from, Carbon $to): array
    {
        if (!Schema::hasTable('workflows') || !Schema::hasTable('workflow_runs')) {
            return ['Процессы', 'Нет таблиц workflow.'];
        }

        $runsQuery = DB::table('workflow_runs')
            ->whereBetween('created_at', [$from, $to]);

        $totalRuns = (clone $runsQuery)->count();
        $failedRuns = (clone $runsQuery)->where('status', 'failed')->count();
        $completedRuns = (clone $runsQuery)->where('status', 'completed')->count();
        $runningRuns = (clone $runsQuery)->whereIn('status', ['pending', 'running', 'paused'])->count();
        $stepTransactions = Schema::hasTable('workflow_run_steps')
            ? DB::table('workflow_run_steps')->whereBetween('created_at', [$from, $to])->count()
            : 0;

        $lines = [
            'Процессы',
            "Запусков: {$totalRuns} / завершено: {$completedRuns} / ошибок: {$failedRuns} / в работе: {$runningRuns}",
            'Транзакций шагов: ' . $stepTransactions,
            'Активных сценариев: ' . DB::table('workflows')->where('is_active', true)->count(),
        ];

        $topRuns = $this->topWorkflows($from, $to, onlyFailed: false);
        if ($topRuns !== []) {
            $lines[] = 'Топ запусков: ' . implode('; ', $topRuns);
        }

        $topErrors = $this->topWorkflows($from, $to, onlyFailed: true);
        if ($topErrors !== []) {
            $lines[] = 'Топ ошибок: ' . implode('; ', $topErrors);
        }

        return $lines;
    }

    private function queueLines(Carbon $from, Carbon $to): array
    {
        $lines = ['Очереди'];

        $pendingJobs = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
        $reservedJobs = Schema::hasTable('jobs') ? DB::table('jobs')->whereNotNull('reserved_at')->count() : 0;
        $failedJobs = $this->countBetween('failed_jobs', 'failed_at', $from, $to);

        $lines[] = "Ожидают: {$pendingJobs} / выполняются: {$reservedJobs} / failed за период: {$failedJobs}";

        if (Schema::hasTable('queue_monitors')) {
            $monitors = DB::table('queue_monitors')
                ->whereBetween('created_at', [$from, $to]);

            $finished = (clone $monitors)->whereNotNull('finished_at')->count();
            $failed = (clone $monitors)->where('failed', true)->count();
            $lines[] = "Монитор задач: завершено {$finished}, ошибок {$failed}";
        }

        return $lines;
    }

    private function topWorkflows(Carbon $from, Carbon $to, bool $onlyFailed): array
    {
        $query = DB::table('workflow_runs')
            ->join('workflows', 'workflows.id', '=', 'workflow_runs.workflow_id')
            ->whereBetween('workflow_runs.created_at', [$from, $to]);

        if ($onlyFailed) {
            $query->where('workflow_runs.status', 'failed');
        }

        return $query
            ->selectRaw('workflows.name, count(*) as total')
            ->groupBy('workflows.id', 'workflows.name')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(static fn($row): string => Str::limit((string)$row->name, 28, '...') . ' - ' . $row->total)
            ->all();
    }

    private function countBetween(string $table, string $column, Carbon $from, Carbon $to): int
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return 0;
        }

        return (int)DB::table($table)
            ->whereBetween($column, [$from, $to])
            ->count();
    }

    private function countAccountsBetween(Carbon $from, Carbon $to): int
    {
        if (!Schema::hasTable('accounts') || !Schema::hasColumn('accounts', 'created_at')) {
            return 0;
        }

        $query = DB::table('accounts');

        if (Schema::hasColumn('accounts', 'active')) {
            $query->where('active', true);
        }

        return (int)$query
            ->whereBetween('created_at', [$from->timestamp, $to->timestamp])
            ->count();
    }

    private function countWhere(string $table, array $where): int
    {
        if (!Schema::hasTable($table)) {
            return 0;
        }

        $query = DB::table($table);

        foreach ($where as $column => $value) {
            if (!Schema::hasColumn($table, $column)) {
                return 0;
            }

            $query->where($column, $value);
        }

        return (int)$query->count();
    }

    private function countActiveSubscriptions(): int
    {
        if (!Schema::hasTable('widget_subscriptions')) {
            return 0;
        }

        $today = now()->toDateString();

        return (int)$this->subscriptionsQuery()
            ->whereNull('blocked_at')
            ->whereIn('status', WidgetSubscription::ACTIVE_STATUSES)
            ->where(static function (Builder $query) use ($today): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $today);
            })
            ->where(static function (Builder $query) use ($today): void {
                $query
                    ->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', $today)
                    ->orWhere('grace_until', '>=', $today);
            })
            ->count();
    }

    private function countInactiveSubscriptions(): int
    {
        if (!Schema::hasTable('widget_subscriptions')) {
            return 0;
        }

        return (int)$this->subscriptionsQuery()
            ->where(static function (Builder $query): void {
                $query
                    ->whereIn('status', [
                        WidgetSubscription::STATUS_EXPIRED,
                        WidgetSubscription::STATUS_BLOCKED,
                        WidgetSubscription::STATUS_CANCELLED,
                    ])
                    ->orWhereNotNull('blocked_at');
            })
            ->count();
    }

    private function subscriptionsQuery(): Builder
    {
        $query = DB::table('widget_subscriptions');

        if (Schema::hasColumn('widget_subscriptions', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query;
    }

    private function countOpenInvoiceRequests(): int
    {
        if (!Schema::hasTable('subscription_invoice_requests')) {
            return 0;
        }

        $query = DB::table('subscription_invoice_requests')
            ->whereIn('status', [
                SubscriptionInvoiceRequest::STATUS_NEW,
                SubscriptionInvoiceRequest::STATUS_IN_PROGRESS,
                SubscriptionInvoiceRequest::STATUS_INVOICE_SENT,
            ]);

        if (Schema::hasColumn('subscription_invoice_requests', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return (int)$query->count();
    }
}

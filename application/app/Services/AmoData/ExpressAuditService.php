<?php

namespace App\Services\AmoData;

use App\Models\Integrations\AmoData\Setting;
use App\Models\amoCRM\Lead;
use App\Models\amoCRM\Task;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ExpressAuditService
{
    private const STALLED_LEAD_DAYS = 14;

    private const RESPONSE_LOOKBACK_DAYS = 30;

    public function buildForSetting(Setting $setting): array
    {
        $account = $setting->amoAccount(false, 'amo-data');

        if (!$account) {
            return [
                'ready' => false,
                'title' => 'Аккаунт amoCRM не подключен',
                'message' => 'Подключите amoCRM для виджета amo-data, чтобы получить экспресс-аудит.',
            ];
        }

        $leadQuery = Lead::query()
            ->where('user_id', $setting->user_id)
            ->where('account_id', $account->id);

        $taskQuery = Task::query()
            ->where('user_id', $setting->user_id)
            ->where('account_id', $account->id);

        $totalLeads = (clone $leadQuery)->count();
        $totalTasks = (clone $taskQuery)->count();

        if ($totalLeads === 0 && $totalTasks === 0) {
            return [
                'ready' => false,
                'title' => 'Недостаточно данных',
                'message' => 'Сначала выполните выгрузку сделок и задач, после этого появится экспресс-аудит.',
            ];
        }

        $wonLeads = (clone $leadQuery)->where('is_won', true)->count();
        $lostLeads = (clone $leadQuery)->where('is_lost', true)->count();
        $closedLeads = $wonLeads + $lostLeads;
        $openLeads = (clone $leadQuery)->where('is_closed', false)->count();

        $winRate = $this->percent($wonLeads, $closedLeads);

        $openTasks = (clone $taskQuery)->where('is_completed', false)->count();
        $overdueTasks = (clone $taskQuery)
            ->where('is_completed', false)
            ->whereNotNull('complete_till')
            ->where('complete_till', '<', now())
            ->count();

        $overdueRate = $this->percent($overdueTasks, $openTasks);

        $stalledLeads = (clone $leadQuery)
            ->where('is_closed', false)
            ->whereNotNull('amo_updated_at')
            ->where('amo_updated_at', '<', now()->subDays(self::STALLED_LEAD_DAYS))
            ->count();

        $stalledRate = $this->percent($stalledLeads, $openLeads);

        $filledLeads = (clone $leadQuery)
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->whereNotNull('pipeline_id')
            ->whereNotNull('status_id')
            ->whereNotNull('responsible_user_id')
            ->whereNotNull('price')
            ->where('price', '>', 0)
            ->count();

        $completenessRate = $this->percent($filledLeads, $totalLeads);

        $responseStats = $this->calculateResponseStats($setting->user_id, $account->id);

        $conversionScore = (int)round(min(100, $winRate * 2));
        $tasksScore = (int)round(max(0, 100 - ($overdueRate * 1.2)));
        $stalledScore = (int)round(max(0, 100 - ($stalledRate * 1.3)));
        $completenessScore = (int)round($completenessRate);
        $reactionScore = $this->reactionScore(
            $responseStats['coverage_rate'],
            $responseStats['avg_response_hours']
        );

        $overallScore = (int)round(
            ($conversionScore * 0.30)
            + ($tasksScore * 0.20)
            + ($stalledScore * 0.20)
            + ($completenessScore * 0.15)
            + ($reactionScore * 0.15)
        );

        $scoreTone = $this->scoreTone($overallScore);
        $scoreLabel = match ($scoreTone) {
            'success' => 'Система в хорошем состоянии',
            'warning' => 'Есть зона роста',
            default => 'Требуется стабилизация',
        };

        $metrics = [
            [
                'key' => 'conversion',
                'label' => 'Конверсия в успешные',
                'value' => $this->formatPercent($winRate),
                'description' => sprintf('Выиграно %d из %d закрытых', $wonLeads, $closedLeads),
                'score' => $conversionScore,
                'tone' => $this->metricTone($conversionScore),
            ],
            [
                'key' => 'overdue_tasks',
                'label' => 'Просроченные задачи',
                'value' => $this->formatPercent($overdueRate),
                'description' => sprintf('%d из %d активных задач', $overdueTasks, $openTasks),
                'score' => $tasksScore,
                'tone' => $this->metricTone($tasksScore),
            ],
            [
                'key' => 'stalled_leads',
                'label' => 'Зависшие сделки',
                'value' => $this->formatPercent($stalledRate),
                'description' => sprintf(
                    '%d из %d открытых без движения более %d дней',
                    $stalledLeads,
                    $openLeads,
                    self::STALLED_LEAD_DAYS
                ),
                'score' => $stalledScore,
                'tone' => $this->metricTone($stalledScore),
            ],
            [
                'key' => 'completeness',
                'label' => 'Заполненность карточек',
                'value' => $this->formatPercent($completenessRate),
                'description' => sprintf('%d из %d карточек заполнены по базовым полям', $filledLeads, $totalLeads),
                'score' => $completenessScore,
                'tone' => $this->metricTone($completenessScore),
            ],
            [
                'key' => 'first_response',
                'label' => 'Скорость первой реакции',
                'value' => $responseStats['avg_response_hours'] === null
                    ? 'Нет данных'
                    : $this->formatHours($responseStats['avg_response_hours']),
                'description' => sprintf(
                    'Реакция есть у %d из %d новых сделок',
                    $responseStats['responded_leads'],
                    $responseStats['recent_leads']
                ),
                'score' => $reactionScore,
                'tone' => $this->metricTone($reactionScore),
            ],
        ];

        $problems = $this->detectProblems(
            winRate: $winRate,
            closedLeads: $closedLeads,
            overdueRate: $overdueRate,
            overdueTasks: $overdueTasks,
            stalledRate: $stalledRate,
            stalledLeads: $stalledLeads,
            completenessRate: $completenessRate,
            totalLeads: $totalLeads,
            coverageRate: $responseStats['coverage_rate'],
            avgResponseHours: $responseStats['avg_response_hours'],
            recentLeads: $responseStats['recent_leads'],
        );

        $topProblems = collect($problems)
            ->sortByDesc('priority')
            ->take(3)
            ->values()
            ->all();

        $actions = collect($topProblems)
            ->map(fn(array $problem) => [
                'title' => $problem['action_title'],
                'description' => $problem['action'],
                'effect' => $problem['effect'],
            ])
            ->values()
            ->all();

        if ($actions === []) {
            $actions[] = [
                'title' => 'Поддерживать текущий темп',
                'description' => 'Критичных перекосов нет. Сфокусируйтесь на росте среднего чека и повторных продажах.',
                'effect' => 'Стабильный контроль качества без срочных доработок.',
            ];
        }

        $openRevenue = (int)((clone $leadQuery)
            ->where('is_closed', false)
            ->sum('price'));

        $avgWonCheck = (float)((clone $leadQuery)
            ->where('is_won', true)
            ->where('price', '>', 0)
            ->avg('price'));

        if ($avgWonCheck <= 0) {
            $avgWonCheck = (float)((clone $leadQuery)
                ->where('price', '>', 0)
                ->avg('price'));
        }

        $recoverableDeals = max(
            0,
            (int)round(
                ($stalledLeads * 0.30)
                + ($responseStats['without_response'] * 0.25)
                + ($overdueTasks * 0.08)
            )
        );

        $potentialAmount = (int)round(max(0, $avgWonCheck) * $recoverableDeals);

        return [
            'ready' => true,
            'generated_at' => now()->toDateTimeString(),
            'score' => [
                'value' => $overallScore,
                'label' => $scoreLabel,
                'tone' => $scoreTone,
            ],
            'summary' => [
                'leads' => $totalLeads,
                'tasks' => $totalTasks,
                'open_leads' => $openLeads,
                'open_revenue' => $openRevenue,
            ],
            'metrics' => $metrics,
            'problems' => $topProblems,
            'actions' => $actions,
            'potential' => [
                'recoverable_deals' => $recoverableDeals,
                'amount' => $potentialAmount,
                'open_revenue' => $openRevenue,
                'note' => 'Оценка основана на зависших сделках, просроченных задачах и пропущенной первой реакции.',
            ],
        ];
    }

    private function percent(int|float $part, int|float $whole): float
    {
        if ($whole <= 0) {
            return 0.0;
        }

        return round(($part / $whole) * 100, 1);
    }

    /**
     * @return array{recent_leads:int,responded_leads:int,without_response:int,coverage_rate:float,avg_response_hours:float|null}
     */
    private function calculateResponseStats(int $userId, int $accountId): array
    {
        $windowFrom = now()->subDays(self::RESPONSE_LOOKBACK_DAYS);

        $rows = DB::table('amocrm_leads as leads')
            ->leftJoin('amocrm_tasks as tasks', function ($join): void {
                $join->on('tasks.entity_id', '=', 'leads.amo_id')
                    ->whereIn('tasks.entity_type', ['lead', 'leads'])
                    ->whereColumn('tasks.user_id', 'leads.user_id')
                    ->whereColumn('tasks.account_id', 'leads.account_id');
            })
            ->where('leads.user_id', $userId)
            ->where('leads.account_id', $accountId)
            ->whereNotNull('leads.amo_created_at')
            ->where('leads.amo_created_at', '>=', $windowFrom)
            ->selectRaw('leads.id, leads.amo_created_at, MIN(tasks.amo_created_at) as first_task_at')
            ->groupBy('leads.id', 'leads.amo_created_at')
            ->get();

        $recentLeads = $rows->count();
        $respondedLeads = 0;
        $withoutResponse = 0;
        $responseDelaysHours = [];

        foreach ($rows as $row) {
            if (!$row->first_task_at) {
                $withoutResponse++;

                continue;
            }

            $createdAt = Carbon::parse($row->amo_created_at);
            $firstTaskAt = Carbon::parse($row->first_task_at);

            $delayMinutes = max(0, $createdAt->diffInMinutes($firstTaskAt, false));

            $responseDelaysHours[] = $delayMinutes / 60;
            $respondedLeads++;
        }

        return [
            'recent_leads' => $recentLeads,
            'responded_leads' => $respondedLeads,
            'without_response' => $withoutResponse,
            'coverage_rate' => $this->percent($respondedLeads, $recentLeads),
            'avg_response_hours' => $responseDelaysHours === []
                ? null
                : round(collect($responseDelaysHours)->avg(), 1),
        ];
    }

    private function reactionScore(float $coverageRate, ?float $avgResponseHours): int
    {
        if ($avgResponseHours === null) {
            return (int)round($coverageRate * 0.6);
        }

        $speedScore = match (true) {
            $avgResponseHours <= 1 => 100,
            $avgResponseHours <= 4 => 85,
            $avgResponseHours <= 24 => 65,
            $avgResponseHours <= 72 => 40,
            default => 20,
        };

        return (int)round(($coverageRate * 0.55) + ($speedScore * 0.45));
    }

    private function scoreTone(int $score): string
    {
        return match (true) {
            $score >= 75 => 'success',
            $score >= 50 => 'warning',
            default => 'danger',
        };
    }

    private function formatPercent(float $value): string
    {
        return number_format($value, 1, '.', ' ') . '%';
    }

    private function metricTone(int $score): string
    {
        return match (true) {
            $score >= 75 => 'success',
            $score >= 50 => 'warning',
            default => 'danger',
        };
    }

    private function formatHours(float $hours): string
    {
        return number_format($hours, 1, '.', ' ') . ' ч';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function detectProblems(
        float $winRate,
        int $closedLeads,
        float $overdueRate,
        int $overdueTasks,
        float $stalledRate,
        int $stalledLeads,
        float $completenessRate,
        int $totalLeads,
        float $coverageRate,
        ?float $avgResponseHours,
        int $recentLeads,
    ): array {
        $problems = [];

        if ($closedLeads >= 5 && $winRate < 20) {
            $problems[] = [
                'title' => 'Низкая конверсия в успешные сделки',
                'description' => sprintf('Текущая конверсия в выигрыш: %s.', $this->formatPercent($winRate)),
                'priority' => 100,
                'action_title' => 'Пересобрать воронку на узком этапе',
                'action' => 'Разберите 20 последних проигранных сделок и обновите скрипт/оффер на этапе с наибольшим отвалом.',
                'effect' => 'Рост конверсии и более предсказуемый поток выручки.',
            ];
        }

        if ($overdueTasks >= 5 && $overdueRate > 30) {
            $problems[] = [
                'title' => 'Много просроченных задач',
                'description' => sprintf(
                    'Просрочено %d задач (%s от активных).',
                    $overdueTasks,
                    $this->formatPercent($overdueRate)
                ),
                'priority' => 90,
                'action_title' => 'Ввести ежедневный SLA-контроль',
                'action' => 'Соберите отдельный список просрочки и закрепите правило закрывать 80% просрочки в течение дня.',
                'effect' => 'Снижение потерь заявок и ускорение обработки лидов.',
            ];
        }

        if ($stalledLeads >= 5 && $stalledRate > 25) {
            $problems[] = [
                'title' => 'Сделки зависают без движения',
                'description' => sprintf(
                    'Без движения более %d дней: %d сделок (%s).',
                    self::STALLED_LEAD_DAYS,
                    $stalledLeads,
                    $this->formatPercent($stalledRate)
                ),
                'priority' => 85,
                'action_title' => 'Запустить реанимацию зависших',
                'action' => 'Сегментируйте зависшие сделки по стадии и отправьте менеджерам план догрева с конкретным дедлайном.',
                'effect' => 'Быстрые возвраты в работу и заметный прирост закрытий.',
            ];
        }

        if ($totalLeads >= 10 && $completenessRate < 70) {
            $problems[] = [
                'title' => 'Карточки заполнены слабо',
                'description' => sprintf(
                    'Базовые поля заполнены только у %s сделок.',
                    $this->formatPercent($completenessRate)
                ),
                'priority' => 70,
                'action_title' => 'Зафиксировать обязательные поля',
                'action' => 'Включите обязательность суммы, ответственного и этапа для всех новых сделок.',
                'effect' => 'Чище аналитика и меньше ручных уточнений в работе команды.',
            ];
        }

        $slowResponse = $avgResponseHours !== null && $avgResponseHours > 24;

        if ($recentLeads >= 5 && ($coverageRate < 70 || $slowResponse)) {
            $description = $avgResponseHours === null
                ? sprintf('Реакция есть только у %s новых сделок.', $this->formatPercent($coverageRate))
                : sprintf(
                    'Реакция есть у %s новых сделок, среднее время реакции: %s.',
                    $this->formatPercent($coverageRate),
                    $this->formatHours($avgResponseHours)
                );

            $problems[] = [
                'title' => 'Слабая первичная реакция на новые сделки',
                'description' => $description,
                'priority' => 80,
                'action_title' => 'Усилить первый контакт',
                'action' => 'Настройте правило: первая задача в течение 15 минут и контроль его выполнения в конце дня.',
                'effect' => 'Больше лидов переходит в переговоры в первые сутки.',
            ];
        }

        return $problems;
    }
}

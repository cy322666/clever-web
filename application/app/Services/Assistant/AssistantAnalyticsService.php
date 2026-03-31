<?php

namespace App\Services\Assistant;

use App\Models\amoCRM\Lead;
use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Models\amoCRM\Task;
use App\Models\Integrations\Assistant\Setting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class AssistantAnalyticsService
{
    private const WON_STATUS_ID = 142;
    private const LOST_STATUS_ID = 143;

    public function __construct()
    {
    }

    public static function forUser(User $user): self
    {
        return new self();
    }

    public function managerSummary(User $user, Setting $setting, int $managerId, int $limit = 10): array
    {
        $snapshot = $this->snapshot($user, $setting);
        $manager = collect($snapshot['staffs'])->firstWhere('staff_id', $managerId);
        $leads = collect($snapshot['leads'])->where('responsible_user_id', $managerId)->values();
        $activeDeals = $leads->where('is_closed', false)->values();
        $overdueTasks = collect($snapshot['tasks'])
            ->where('responsible_user_id', $managerId)
            ->where('is_overdue', true)
            ->values();

        return [
            'manager' => $manager,
            'totals' => [
                'active_deals' => $activeDeals->count(),
                'won_deals' => $leads->where('is_won', true)->count(),
                'lost_deals' => $leads->where('is_lost', true)->count(),
                'overdue_tasks' => $overdueTasks->count(),
                'deals_without_next_task' => $activeDeals->where('has_next_task', false)->count(),
                'risky_deals' => $this->collectRiskyDeals($activeDeals, $setting)->count(),
            ],
            'top_deals' => $this->collectRiskyDeals($activeDeals, $setting)->take($limit)->values()->all(),
            'overdue_tasks' => $overdueTasks->take($limit)->values()->all(),
        ];
    }

    public function dealContext(User $user, Setting $setting, int $dealId): array
    {
        $statusMap = $this->statusMap($user);
        $staffMap = $this->staffMap($user);

        $taskRows = Task::query()
            ->where('user_id', $user->id)
            ->where('entity_type', 'leads')
            ->where('entity_id', $dealId)
            ->get();

        $leadRow = Lead::query()
            ->where('user_id', $user->id)
            ->where('amo_id', $dealId)
            ->firstOrFail();

        $tasks = $taskRows
            ->map(fn(Task $task) => $this->normalizeTaskRow($task, $staffMap))
            ->sortBy('complete_till_at')
            ->values();

        $lead = $this->normalizeLeadRow($leadRow, $statusMap, $staffMap, $taskRows);

        return [
            'deal' => $this->appendRiskContext($lead, $setting),
            'tasks' => $tasks->values()->all(),
            'context' => [
                'has_next_task' => $lead['has_next_task'],
                'is_overdue_by_task' => $lead['is_overdue_by_task'],
                'last_touch_at' => $lead['updated_at'],
            ],
        ];
    }

    public function dailySummary(User $user, Setting $setting, int $limit = 10): array
    {
        return $this->summaryPayload($user, $setting, 1, $limit, 'daily');
    }

    private function summaryPayload(User $user, Setting $setting, int $days, int $limit, string $type): array
    {
        return [
            'type' => $type,
            'generated_at' => now()->toDateTimeString(),
            'department' => $this->departmentSummary($user, $setting, $limit),
            'conversion_delta' => $this->conversionDelta($user, $setting, $days),
            'risky_deals' => $this->riskyDeals($user, $setting, $limit),
            'overdue_tasks' => $this->overdueTasks($user, $setting, $limit),
            'deals_without_next_task' => $this->dealsWithoutNextTask($user, $setting, $limit),
            'unprocessed_leads' => $this->unprocessedLeads($user, $setting, $limit),
        ];
    }

    public function departmentSummary(User $user, Setting $setting, int $limit = 10): array
    {
        $snapshot = $this->snapshot($user, $setting);
        $activeDeals = collect($snapshot['leads'])->where('is_closed', false)->values();
        $overdueTasks = collect($snapshot['tasks'])->where('is_overdue', true)->values();
        $withoutNextTask = $activeDeals->where('has_next_task', false)->values();
        $riskyDeals = $this->collectRiskyDeals($activeDeals, $setting);
        $wonDeals = collect($snapshot['leads'])->where('is_won', true);
        $lostDeals = collect($snapshot['leads'])->where('is_lost', true);

        return [
            'totals' => [
                'active_deals' => $activeDeals->count(),
                'overdue_tasks' => $overdueTasks->count(),
                'deals_without_next_task' => $withoutNextTask->count(),
                'risky_deals' => $riskyDeals->count(),
                'won_deals' => $wonDeals->count(),
                'lost_deals' => $lostDeals->count(),
                'won_budget' => (int)$wonDeals->sum('price'),
                'lost_budget' => (int)$lostDeals->sum('price'),
            ],
            'managers' => $this->managerBreakdown($snapshot['staffs'], $activeDeals, $overdueTasks),
            'top_risky_deals' => $riskyDeals->take($limit)->values()->all(),
        ];
    }

    private function snapshot(User $user, Setting $setting): array
    {
        return Cache::remember(
            'assistant:snapshot:' . $user->id,
            now()->addMinutes(5),
            function () use ($user, $setting) {
                $statusMap = $this->statusMap($user);
                $staffMap = $this->staffMap($user);
                $taskRows = Task::query()
                    ->where('user_id', $user->id)
                    ->get();

                $tasksByLead = $taskRows
                    ->where('entity_type', 'leads')
                    ->groupBy('entity_id');

                $leads = Lead::query()
                    ->where('user_id', $user->id)
                    ->get()
                    ->map(fn(Lead $lead) => $this->normalizeLeadRow(
                        $lead,
                        $statusMap,
                        $staffMap,
                        $tasksByLead->get($lead->amo_id, collect())
                    ))
                    ->values()
                    ->all();

                $tasks = $taskRows
                    ->map(fn(Task $task) => $this->normalizeTaskRow($task, $staffMap))
                    ->values()
                    ->all();

                return [
                    'leads' => $leads,
                    'tasks' => $tasks,
                    'staffs' => $staffMap->values()->map(function (Staff $staff) {
                        return [
                            'staff_id' => (int)$staff->staff_id,
                            'name' => $staff->name,
                            'group_name' => $staff->group_name,
                            'active' => (bool)$staff->active,
                        ];
                    })->all(),
                ];
            }
        );
    }

    private function statusMap(User $user): Collection
    {
        return Status::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy(fn(Status $status) => $status->pipeline_id . ':' . $status->status_id);
    }

    private function staffMap(User $user): Collection
    {
        return Staff::query()
            ->where('user_id', $user->id)
            ->where('active', true)
            ->get()
            ->keyBy('staff_id');
    }

    private function normalizeLeadRow(
        Lead $lead,
        Collection $statusMap,
        Collection $staffMap,
        iterable $taskRows
    ): array {
        $responsibleUserId = (int)($lead->responsible_user_id ?? 0);
        $statusKey = (int)($lead->pipeline_id ?? 0) . ':' . (int)($lead->status_id ?? 0);
        /** @var Status|null $status */
        $status = $statusMap->get($statusKey);
        /** @var Staff|null $staff */
        $staff = $staffMap->get($responsibleUserId);
        $createdAt = $this->modelTimestamp($lead->amo_created_at);
        $updatedAt = $this->modelTimestamp($lead->amo_updated_at);
        $closedAt = $this->modelTimestamp($lead->closed_at);
        $nextTaskAt = $this->nextTaskTimestamp($taskRows);
        $payload = $lead->payload ?? [];

        return [
            'id' => (int)$lead->amo_id,
            'name' => (string)($lead->name ?? ''),
            'price' => (int)($lead->price ?? 0),
            'pipeline_id' => (int)($lead->pipeline_id ?? 0),
            'pipeline_name' => $status?->pipeline_name,
            'status_id' => (int)($lead->status_id ?? 0),
            'status_name' => $status?->name,
            'responsible_user_id' => $responsibleUserId,
            'responsible_name' => $staff?->name,
            'created_at' => $this->formatTimestamp($createdAt),
            'updated_at' => $this->formatTimestamp($updatedAt),
            'closed_at' => $this->formatTimestamp($closedAt),
            'created_at_timestamp' => $createdAt,
            'updated_at_timestamp' => $updatedAt,
            'closed_at_timestamp' => $closedAt,
            'closest_task_at' => $this->formatTimestamp($nextTaskAt),
            'closest_task_at_timestamp' => $nextTaskAt,
            'has_next_task' => $nextTaskAt !== null,
            'is_overdue_by_task' => $nextTaskAt !== null && $nextTaskAt < time(),
            'is_won' => (bool)$lead->is_won,
            'is_lost' => (bool)$lead->is_lost,
            'is_closed' => (bool)$lead->is_closed,
            'contacts' => collect(data_get($payload, '_embedded.contacts', []))
                ->map(fn(array $contact) => [
                    'id' => (int)($contact['id'] ?? 0),
                    'name' => $contact['name'] ?? null,
                ])
                ->values()
                ->all(),
            'tags' => collect(data_get($payload, '_embedded.tags', []))
                ->pluck('name')
                ->filter()
                ->values()
                ->all(),
        ];
    }

    private function timestamp(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int)$value;
    }

    private function formatTimestamp(?int $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }

        return Carbon::createFromTimestamp($timestamp)->toDateTimeString();
    }

    private function normalizeTaskRow(Task $task, Collection $staffMap): array
    {
        $completeTillAt = $this->modelTimestamp($task->complete_till);
        $responsibleUserId = (int)($task->responsible_user_id ?? 0);
        /** @var Staff|null $staff */
        $staff = $staffMap->get($responsibleUserId);

        return [
            'id' => (int)$task->amo_id,
            'entity_id' => (int)($task->entity_id ?? 0),
            'entity_type' => $task->entity_type ?? null,
            'responsible_user_id' => $responsibleUserId,
            'responsible_name' => $staff?->name,
            'text' => (string)($task->text ?? ''),
            'complete_till_at' => $this->formatTimestamp($completeTillAt),
            'complete_till_at_timestamp' => $completeTillAt,
            'is_completed' => (bool)$task->is_completed,
            'is_overdue' => $completeTillAt !== null && $completeTillAt < time() && !(bool)$task->is_completed,
        ];
    }

    private function nextTaskTimestamp(iterable $taskRows): ?int
    {
        $timestamps = collect($taskRows)
            ->filter(fn(Task $task) => !(bool)$task->is_completed)
            ->map(fn(Task $task) => $this->modelTimestamp($task->complete_till))
            ->filter()
            ->sort()
            ->values();

        return $timestamps->first();
    }

    private function modelTimestamp(mixed $value): ?int
    {
        if (!$value) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->timestamp;
        }

        return Carbon::parse($value)->timestamp;
    }

    private function collectRiskyDeals(Collection $activeDeals, Setting $setting): Collection
    {
        return $activeDeals
            ->map(fn(array $lead) => $this->appendRiskContext($lead, $setting))
            ->filter(fn(array $lead) => ($lead['risk_score'] ?? 0) > 0)
            ->sortByDesc('risk_score')
            ->values();
    }

    private function appendRiskContext(array $lead, Setting $setting): array
    {
        $riskFlags = [];
        $riskScore = 0;
        $settings = $setting->settings ?? [];
        $staleDays = max(1, (int)data_get($settings, 'risk_stale_days', 3));
        $unprocessedHours = max(1, (int)data_get($settings, 'unprocessed_hours', 24));
        $highValueAmount = max(0, (int)data_get($settings, 'high_value_amount', 100000));

        if (!$lead['has_next_task']) {
            $riskFlags[] = 'no_next_task';
            $riskScore += 2;
        }

        if ($lead['is_overdue_by_task']) {
            $riskFlags[] = 'overdue_task';
            $riskScore += 3;
        }

        if ($lead['updated_at_timestamp'] !== null
            && Carbon::createFromTimestamp($lead['updated_at_timestamp'])->lt(now()->subDays($staleDays))) {
            $riskFlags[] = 'stale_update';
            $riskScore += 2;
        }

        if ($lead['price'] >= $highValueAmount
            && $lead['updated_at_timestamp'] !== null
            && Carbon::createFromTimestamp($lead['updated_at_timestamp'])->lt(now()->subDays(2))) {
            $riskFlags[] = 'high_value_stale';
            $riskScore += 2;
        }

        if ($lead['created_at_timestamp'] !== null
            && Carbon::createFromTimestamp($lead['created_at_timestamp'])->lt(now()->subHours($unprocessedHours))
            && $this->isUnprocessedLead($lead, $setting)) {
            $riskFlags[] = 'unprocessed_lead';
            $riskScore += 3;
        }

        $lead['risk_flags'] = $riskFlags;
        $lead['risk_score'] = $riskScore;

        return $lead;
    }

    private function isUnprocessedLead(array $lead, Setting $setting): bool
    {
        if ($lead['is_closed']) {
            return false;
        }

        $statusName = mb_strtolower((string)($lead['status_name'] ?? ''));

        if (in_array($statusName, ['неразобранное', 'новый лид', 'первичный контакт'], true)) {
            return true;
        }

        return !$lead['has_next_task']
            && $lead['created_at_timestamp'] !== null
            && Carbon::createFromTimestamp($lead['created_at_timestamp'])->lt(
                now()->subHours(max(1, (int)data_get($setting->settings ?? [], 'unprocessed_hours', 24)))
            );
    }

    private function managerBreakdown(array $staffs, Collection $activeDeals, Collection $overdueTasks): array
    {
        return collect($staffs)
            ->map(function (array $staff) use ($activeDeals, $overdueTasks) {
                $staffId = (int)($staff['staff_id'] ?? 0);

                return [
                    'staff_id' => $staffId,
                    'name' => $staff['name'] ?? null,
                    'group_name' => $staff['group_name'] ?? null,
                    'active_deals' => $activeDeals->where('responsible_user_id', $staffId)->count(),
                    'overdue_tasks' => $overdueTasks->where('responsible_user_id', $staffId)->count(),
                    'deals_without_next_task' => $activeDeals
                        ->where('responsible_user_id', $staffId)
                        ->where('has_next_task', false)
                        ->count(),
                ];
            })
            ->filter(fn(array $manager) => $manager['active_deals'] > 0 || $manager['overdue_tasks'] > 0)
            ->sortByDesc('active_deals')
            ->values()
            ->all();
    }

    public function conversionDelta(User $user, Setting $setting, int $days = 7): array
    {
        $snapshot = $this->snapshot($user, $setting);
        $leads = collect($snapshot['leads']);

        $currentEnd = now();
        $currentStart = $currentEnd->copy()->subDays($days);
        $previousStart = $currentStart->copy()->subDays($days);

        $current = $this->periodMetrics($leads, $currentStart, $currentEnd);
        $previous = $this->periodMetrics($leads, $previousStart, $currentStart);

        return [
            'period' => [
                'from' => $currentStart->toDateTimeString(),
                'to' => $currentEnd->toDateTimeString(),
                'days' => $days,
            ],
            'current' => $current,
            'previous' => $previous,
            'delta' => [
                'created' => $this->percentDelta($current['created'], $previous['created']),
                'won' => $this->percentDelta($current['won'], $previous['won']),
                'lost' => $this->percentDelta($current['lost'], $previous['lost']),
                'conversion_rate' => $this->percentDelta($current['conversion_rate'], $previous['conversion_rate']),
                'won_budget' => $this->percentDelta($current['won_budget'], $previous['won_budget']),
            ],
        ];
    }

    private function periodMetrics(Collection $leads, Carbon $from, Carbon $to): array
    {
        $created = $leads->filter(function (array $lead) use ($from, $to) {
            return $lead['created_at_timestamp'] !== null
                && Carbon::createFromTimestamp($lead['created_at_timestamp'])->between($from, $to);
        });

        $won = $leads->filter(function (array $lead) use ($from, $to) {
            return $lead['is_won']
                && $lead['closed_at_timestamp'] !== null
                && Carbon::createFromTimestamp($lead['closed_at_timestamp'])->between($from, $to);
        });

        $lost = $leads->filter(function (array $lead) use ($from, $to) {
            return $lead['is_lost']
                && $lead['closed_at_timestamp'] !== null
                && Carbon::createFromTimestamp($lead['closed_at_timestamp'])->between($from, $to);
        });

        $closedCount = $won->count() + $lost->count();

        return [
            'created' => $created->count(),
            'won' => $won->count(),
            'lost' => $lost->count(),
            'won_budget' => (int)$won->sum('price'),
            'conversion_rate' => $closedCount > 0 ? round(($won->count() / $closedCount) * 100, 2) : 0.0,
        ];
    }

    private function percentDelta(int|float $current, int|float $previous): ?float
    {
        if ((float)$previous === 0.0) {
            return (float)$current === 0.0 ? 0.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 2);
    }

    public function riskyDeals(User $user, Setting $setting, int $limit = 20): array
    {
        $snapshot = $this->snapshot($user, $setting);
        $activeDeals = collect($snapshot['leads'])->where('is_closed', false)->values();
        $items = $this->collectRiskyDeals($activeDeals, $setting);

        return [
            'totals' => [
                'count' => $items->count(),
            ],
            'items' => $items->take($limit)->values()->all(),
        ];
    }

    public function overdueTasks(User $user, Setting $setting, int $limit = 20): array
    {
        $snapshot = $this->snapshot($user, $setting);
        $allItems = collect($snapshot['tasks'])
            ->where('is_overdue', true)
            ->sortBy('complete_till_at_timestamp');

        return [
            'totals' => [
                'count' => $allItems->count(),
            ],
            'items' => $allItems->take($limit)->values()->all(),
        ];
    }

    public function dealsWithoutNextTask(User $user, Setting $setting, int $limit = 20): array
    {
        $snapshot = $this->snapshot($user, $setting);
        $allItems = collect($snapshot['leads'])
            ->where('is_closed', false)
            ->where('has_next_task', false)
            ->sortByDesc('updated_at_timestamp');

        return [
            'totals' => [
                'count' => $allItems->count(),
            ],
            'items' => $allItems->take($limit)->values()->all(),
        ];
    }

    public function unprocessedLeads(User $user, Setting $setting, int $limit = 20): array
    {
        $snapshot = $this->snapshot($user, $setting);
        $allItems = collect($snapshot['leads'])
            ->where('is_closed', false)
            ->filter(fn(array $lead) => $this->isUnprocessedLead($lead, $setting))
            ->sortByDesc('created_at_timestamp');

        return [
            'totals' => [
                'count' => $allItems->count(),
            ],
            'items' => $allItems->take($limit)->values()->all(),
        ];
    }

    public function weeklySummary(User $user, Setting $setting, int $limit = 10): array
    {
        return $this->summaryPayload($user, $setting, 7, $limit, 'weekly');
    }
}

<?php

namespace App\Services\AmoData;

use App\Models\Core\Account;
use App\Models\User;
use App\Models\amoCRM\Event;
use App\Models\amoCRM\Lead;
use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class LeadSyncService
{
    public function sync(User $user, Account $account, array $items): array
    {
        if ($items === []) {
            return [
                'synced' => 0,
                'events' => 0,
            ];
        }

        $amoIds = collect($items)
            ->pluck('id')
            ->filter()
            ->map(fn($id) => (int)$id)
            ->values();

        /** @var Collection<int, Lead> $existing */
        $existing = Lead::query()
            ->where('user_id', $user->id)
            ->whereIn('amo_id', $amoIds)
            ->get()
            ->keyBy('amo_id');

        $statuses = Status::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy(fn(Status $status) => $status->pipeline_id . ':' . $status->status_id);

        $staffs = Staff::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('staff_id');

        $synced = 0;
        $events = 0;

        foreach ($items as $item) {
            $attributes = $this->normalize($user, $account, $item, $statuses, $staffs);
            $current = $existing->get($attributes['amo_id']);

            $lead = Lead::query()->updateOrCreate([
                'user_id' => $user->id,
                'amo_id' => $attributes['amo_id'],
            ], $attributes);

            $events += $this->storeEvents($lead, $current, $attributes, $account);
            $synced++;
        }

        return [
            'synced' => $synced,
            'events' => $events,
        ];
    }

    private function normalize(
        User $user,
        Account $account,
        array $item,
        Collection $statuses,
        Collection $staffs,
    ): array {
        $pipelineId = $this->int($item['pipeline_id'] ?? null);
        $statusId = $this->int($item['status_id'] ?? null);
        $responsibleId = $this->int($item['responsible_user_id'] ?? null);

        /** @var Status|null $status */
        $status = $statuses->get($pipelineId . ':' . $statusId);
        /** @var Staff|null $staff */
        $staff = $staffs->get($responsibleId);

        $isWon = (bool)($status?->is_won ?? $statusId === 142);
        $isLost = (bool)($status?->is_lost ?? $statusId === 143);
        $isClosed = (bool)($status?->is_closed ?? ($isWon || $isLost));

        $amoUpdatedAt = $this->timestamp($item['updated_at'] ?? null);

        return [
            'user_id' => $user->id,
            'account_id' => $account->id,
            'amocrm_status_id' => $status?->id,
            'amocrm_staff_id' => $staff?->id,
            'amo_id' => $this->int($item['id'] ?? null),
            'name' => $item['name'] ?? null,
            'pipeline_id' => $pipelineId,
            'status_id' => $statusId,
            'responsible_user_id' => $responsibleId,
            'price' => $this->int($item['price'] ?? null),
            'amo_created_at' => $this->timestamp($item['created_at'] ?? null),
            'amo_updated_at' => $amoUpdatedAt,
            'closed_at' => $this->timestamp($item['closed_at'] ?? null) ?? ($isClosed ? $amoUpdatedAt : null),
            'is_closed' => $isClosed,
            'is_won' => $isWon,
            'is_lost' => $isLost,
            'payload' => $item,
        ];
    }

    private function int(mixed $value): ?int
    {
        return $value === null ? null : (int)$value;
    }

    private function timestamp(mixed $value): ?Carbon
    {
        if (!$value) {
            return null;
        }

        return Carbon::createFromTimestamp((int)$value);
    }

    private function storeEvents(Lead $lead, ?Lead $current, array $attributes, Account $account): int
    {
        $count = 0;
        $eventAt = $attributes['amo_updated_at'] ?? now();

        if (!$current) {
            $count += $this->event($lead, $account, [
                'entity_type' => 'lead',
                'entity_amo_id' => $lead->amo_id,
                'event_type' => 'deal_created',
                'event_key' => 'deal_created:' . $lead->amo_id . ':' . optional(
                        $attributes['amo_created_at']
                    )->timestamp,
                'event_at' => $attributes['amo_created_at'] ?? $eventAt,
                'to_pipeline_id' => $lead->pipeline_id,
                'to_status_id' => $lead->status_id,
                'to_responsible_user_id' => $lead->responsible_user_id,
            ]);

            if ($lead->is_won) {
                $count += $this->event($lead, $account, [
                    'entity_type' => 'lead',
                    'entity_amo_id' => $lead->amo_id,
                    'event_type' => 'deal_closed_won',
                    'event_key' => 'deal_closed_won:' . $lead->amo_id . ':' . optional($lead->closed_at)->timestamp,
                    'event_at' => $lead->closed_at ?? $eventAt,
                    'to_pipeline_id' => $lead->pipeline_id,
                    'to_status_id' => $lead->status_id,
                ]);
            } elseif ($lead->is_lost) {
                $count += $this->event($lead, $account, [
                    'entity_type' => 'lead',
                    'entity_amo_id' => $lead->amo_id,
                    'event_type' => 'deal_closed_lost',
                    'event_key' => 'deal_closed_lost:' . $lead->amo_id . ':' . optional($lead->closed_at)->timestamp,
                    'event_at' => $lead->closed_at ?? $eventAt,
                    'to_pipeline_id' => $lead->pipeline_id,
                    'to_status_id' => $lead->status_id,
                ]);
            }

            return $count;
        }

        if ($current->status_id !== $lead->status_id || $current->pipeline_id !== $lead->pipeline_id) {
            $count += $this->event($lead, $account, [
                'entity_type' => 'lead',
                'entity_amo_id' => $lead->amo_id,
                'event_type' => 'deal_stage_changed',
                'event_key' => 'deal_stage_changed:' . $lead->amo_id . ':' . $lead->pipeline_id . ':' . $lead->status_id . ':' . optional(
                        $eventAt
                    )->timestamp,
                'event_at' => $eventAt,
                'from_pipeline_id' => $current->pipeline_id,
                'to_pipeline_id' => $lead->pipeline_id,
                'from_status_id' => $current->status_id,
                'to_status_id' => $lead->status_id,
            ]);
        }

        if ($current->responsible_user_id !== $lead->responsible_user_id) {
            $count += $this->event($lead, $account, [
                'entity_type' => 'lead',
                'entity_amo_id' => $lead->amo_id,
                'event_type' => 'deal_responsible_changed',
                'event_key' => 'deal_responsible_changed:' . $lead->amo_id . ':' . $lead->responsible_user_id . ':' . optional(
                        $eventAt
                    )->timestamp,
                'event_at' => $eventAt,
                'from_responsible_user_id' => $current->responsible_user_id,
                'to_responsible_user_id' => $lead->responsible_user_id,
            ]);
        }

        if (!$current->is_closed && $lead->is_won) {
            $count += $this->event($lead, $account, [
                'entity_type' => 'lead',
                'entity_amo_id' => $lead->amo_id,
                'event_type' => 'deal_closed_won',
                'event_key' => 'deal_closed_won:' . $lead->amo_id . ':' . optional($lead->closed_at)->timestamp,
                'event_at' => $lead->closed_at ?? $eventAt,
                'to_pipeline_id' => $lead->pipeline_id,
                'to_status_id' => $lead->status_id,
            ]);
        }

        if (!$current->is_closed && $lead->is_lost) {
            $count += $this->event($lead, $account, [
                'entity_type' => 'lead',
                'entity_amo_id' => $lead->amo_id,
                'event_type' => 'deal_closed_lost',
                'event_key' => 'deal_closed_lost:' . $lead->amo_id . ':' . optional($lead->closed_at)->timestamp,
                'event_at' => $lead->closed_at ?? $eventAt,
                'to_pipeline_id' => $lead->pipeline_id,
                'to_status_id' => $lead->status_id,
            ]);
        }

        return $count;
    }

    private function event(Lead $lead, Account $account, array $payload): int
    {
        $event = Event::query()->firstOrCreate([
            'user_id' => $lead->user_id,
            'event_key' => $payload['event_key'],
        ],
            array_merge($payload, [
                'account_id' => $account->id,
                'lead_id' => $lead->id,
            ]));

        return $event->wasRecentlyCreated ? 1 : 0;
    }
}

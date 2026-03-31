<?php

namespace App\Services\AmoData;

use App\Models\Core\Account;
use App\Models\User;
use App\Models\amoCRM\Event;
use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Task;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TaskSyncService
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

        /** @var Collection<int, Task> $existing */
        $existing = Task::query()
            ->where('user_id', $user->id)
            ->whereIn('amo_id', $amoIds)
            ->get()
            ->keyBy('amo_id');

        $staffs = Staff::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('staff_id');

        $synced = 0;
        $events = 0;

        foreach ($items as $item) {
            $attributes = $this->normalize($user, $account, $item, $staffs);
            $current = $existing->get($attributes['amo_id']);

            $task = Task::query()->updateOrCreate([
                'user_id' => $user->id,
                'amo_id' => $attributes['amo_id'],
            ], $attributes);

            $events += $this->storeEvents($task, $current, $account);
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
        Collection $staffs,
    ): array {
        $responsibleId = $this->int($item['responsible_user_id'] ?? null);
        /** @var Staff|null $staff */
        $staff = $staffs->get($responsibleId);

        $completed = (bool)($item['is_completed'] ?? false);
        $updatedAt = $this->timestamp($item['updated_at'] ?? null);

        return [
            'user_id' => $user->id,
            'account_id' => $account->id,
            'amocrm_staff_id' => $staff?->id,
            'amo_id' => $this->int($item['id'] ?? null),
            'entity_type' => $item['entity_type'] ?? null,
            'entity_id' => $this->int($item['entity_id'] ?? null),
            'responsible_user_id' => $responsibleId,
            'type_id' => $this->int($item['task_type_id'] ?? null),
            'text' => $item['text'] ?? null,
            'complete_till' => $this->timestamp($item['complete_till'] ?? null),
            'is_completed' => $completed,
            'amo_created_at' => $this->timestamp($item['created_at'] ?? null),
            'amo_updated_at' => $updatedAt,
            'completed_at' => $this->timestamp($item['completed_at'] ?? null)
                ?? ($completed ? $updatedAt : null),
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

    private function storeEvents(Task $task, ?Task $current, Account $account): int
    {
        $count = 0;

        if (!$current) {
            $count += $this->event($task, $account, [
                'entity_type' => 'task',
                'entity_amo_id' => $task->amo_id,
                'event_type' => 'task_created',
                'event_key' => 'task_created:' . $task->amo_id . ':' . optional($task->amo_created_at)->timestamp,
                'event_at' => $task->amo_created_at ?? $task->amo_updated_at ?? now(),
                'to_responsible_user_id' => $task->responsible_user_id,
                'meta' => [
                    'entity_type' => $task->entity_type,
                    'entity_id' => $task->entity_id,
                ],
            ]);

            if ($task->is_completed) {
                $count += $this->event($task, $account, [
                    'entity_type' => 'task',
                    'entity_amo_id' => $task->amo_id,
                    'event_type' => 'task_completed',
                    'event_key' => 'task_completed:' . $task->amo_id . ':' . optional($task->completed_at)->timestamp,
                    'event_at' => $task->completed_at ?? $task->amo_updated_at ?? now(),
                    'to_responsible_user_id' => $task->responsible_user_id,
                ]);
            }

            return $count;
        }

        if (!$current->is_completed && $task->is_completed) {
            $count += $this->event($task, $account, [
                'entity_type' => 'task',
                'entity_amo_id' => $task->amo_id,
                'event_type' => 'task_completed',
                'event_key' => 'task_completed:' . $task->amo_id . ':' . optional($task->completed_at)->timestamp,
                'event_at' => $task->completed_at ?? $task->amo_updated_at ?? now(),
                'to_responsible_user_id' => $task->responsible_user_id,
            ]);
        }

        return $count;
    }

    private function event(Task $task, Account $account, array $payload): int
    {
        $event = Event::query()->firstOrCreate([
            'user_id' => $task->user_id,
            'event_key' => $payload['event_key'],
        ],
            array_merge($payload, [
                'account_id' => $account->id,
                'task_id' => $task->id,
            ]));

        return $event->wasRecentlyCreated ? 1 : 0;
    }
}

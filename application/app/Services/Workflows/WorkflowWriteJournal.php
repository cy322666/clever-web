<?php

namespace App\Services\Workflows;

use App\Models\Core\Account;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Leek\FilamentWorkflows\Context\WorkflowContext;

final class WorkflowWriteJournal
{
    public static function enabled(): bool
    {
        return (bool) config('filament-workflows.execution.precise_loop_guard', false);
    }

    public function target(string $method, string $path): ?array
    {
        if (! in_array($method, ['POST', 'PATCH', 'DELETE'])) {
            return null;
        }
        if (! preg_match('~^/api/v4/(leads|contacts|companies|customers|tasks)(?:/(\d+))?(?:/(notes|link|unlink))?$~', $path, $m)) {
            return null;
        }
        $entity = ['leads' => 'lead', 'contacts' => 'contact', 'companies' => 'company', 'customers' => 'customer', 'tasks' => 'task'][$m[1]];

        return ['entity' => $entity, 'entity_id' => (int) ($m[2] ?? 0), 'operation' => $m[3] ?? ($method === 'POST' ? 'create' : 'update')];
    }

    public function begin(Account $account, ?WorkflowContext $context, array $target, array $payload, array $before = [], array $relatedBefore = []): ?string
    {
        if (! self::enabled() || ! $context?->getWorkflowId()) {
            return null;
        }
        $id = (string) Str::uuid();
        $row = array_is_list($payload) ? ($payload[0] ?? []) : $payload;
        $related = $this->related($target, $row);
        DB::table('workflow_write_intents')->insert([
            'id' => $id, 'user_id' => $account->user_id, 'account_id' => $account->id,
            'workflow_id' => $context->getWorkflowId(), 'run_id' => $context->getWorkflowRunId(),
            ...$target, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            'related_entity' => $related['entity'] ?? null, 'related_id' => $related['id'] ?? null,
            'evidence' => json_encode(['before' => WorkflowMutationEvidence::fields($before),
                'before_revision' => WorkflowMutationEvidence::revision($before),
                'related_before_revision' => WorkflowMutationEvidence::revision($relatedBefore),
                'expected' => WorkflowMutationEvidence::fields($row),
                'related' => $this->related($target, $row),
                'supported' => array_diff(array_keys($row), ['id', 'name', 'price', 'status_id', 'pipeline_id', 'responsible_user_id',
                    'first_name', 'last_name', 'is_completed', 'text', 'task_type_id', 'complete_till', 'next_price', 'next_date',
                    'loss_reason_id', 'closed_at', 'custom_fields_values']) === []], JSON_THROW_ON_ERROR),
        ]);

        return $id;
    }

    public function related(array $target, array $row): ?array
    {
        if (! in_array($target['operation'], ['link', 'unlink'])) {
            return null;
        }
        $entity = ['leads' => 'lead', 'contacts' => 'contact', 'companies' => 'company', 'customers' => 'customer'][$row['to_entity_type'] ?? ''] ?? null;

        return $entity ? ['entity' => $entity, 'id' => (int) ($row['to_entity_id'] ?? 0)] : null;
    }

    public function finish(?string $id, string $status, array $body = [], array $after = [], array $relatedAfter = []): void
    {
        if ($id === null) {
            return;
        }
        $intent = DB::table('workflow_write_intents')->where('id', $id)->firstOrFail();
        $evidence = json_decode($intent->evidence, true, 512, JSON_THROW_ON_ERROR);
        $rows = array_filter($body['_embedded'] ?? [], fn ($v) => is_array($v) && array_is_list($v));
        $result = isset($body['id']) ? $body : ($rows ? (reset($rows)[0] ?? []) : []);
        $evidence['revision'] = WorkflowMutationEvidence::revision($result);
        $evidence['result_id'] = (int) ($result['id'] ?? 0);
        if (in_array($intent->operation, ['link', 'unlink'])) {
            $evidence['revision_range'] = [$evidence['before_revision'], WorkflowMutationEvidence::revision($after)];
            $evidence['related_revision_range'] = [$evidence['related_before_revision'], WorkflowMutationEvidence::revision($relatedAfter)];
        }
        DB::table('workflow_write_intents')->where('id', $id)->update([
            'status' => $status, 'updated_at' => now(), 'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR),
            'entity_id' => $intent->operation === 'create' ? ($result['id'] ?? 0) : $intent->entity_id,
        ]);
    }

    /** No cache fallback: unavailable durable evidence must never silently permit a loop. */
    public function decide(Account $account, array $event): array
    {
        $item = $event['item'] ?? [];
        $entity = $event['entity'] ?? '';
        $isNote = str_starts_with($event['event'] ?? '', 'note_');
        $entityId = (int) ($isNote ? ($item['element_id'] ?? $item['entity_id'] ?? 0) : ($item['id'] ?? 0));
        $rows = DB::table('workflow_write_intents')->where('account_id', $account->id)->where('user_id', $account->user_id)
            ->where('status', '!=', 'failed')
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('entity', $entity)->whereIn('entity_id', [$entityId, 0]))
                ->orWhere(fn ($q) => $q->where('related_entity', $entity)->where('related_id', $entityId)))
            ->orderByDesc('created_at')->cursor();
        $review = null;
        foreach ($rows as $row) {
            $proof = json_decode($row->evidence, true, 512, JSON_THROW_ON_ERROR);
            $related = $proof['related'] ?? null;
            $primary = $row->entity === $entity && ((int) $row->entity_id === $entityId || ((int) $row->entity_id === 0 && $row->operation === 'create' && str_starts_with($event['event'] ?? '', 'add_')));
            $secondary = $related && $related['entity'] === $entity && (int) $related['id'] === $entityId;
            if (! $primary && ! $secondary) {
                continue;
            }
            if ($isNote !== ($row->operation === 'notes')) {
                continue;
            }
            if ($row->operation === 'create' && ! str_starts_with($event['event'] ?? '', 'add_')) {
                continue;
            }
            if ($row->operation !== 'create' && str_starts_with($event['event'] ?? '', 'add_')) {
                continue;
            }
            $decision = $this->classify($row, $proof, $event);
            if ($decision['decision'] === 'own') {
                return $decision;
            }
            if ($decision['decision'] === 'review') {
                $review = $decision;
            }
        }

        return $review ?? ['decision' => 'external', 'reason' => 'Не совпадает с собственным изменением'];
    }

    private function classify(object $row, array $proof, array $event): array
    {
        $item = $event['item'] ?? [];
        $revision = WorkflowMutationEvidence::revision($item);
        $savedRevision = $proof['revision'] ?? null;
        $review = ['decision' => 'review', 'reason' => 'Источник изменения не подтверждён. Проверьте событие.', 'intent_id' => $row->id];
        if ($row->status !== 'confirmed') {
            return $review;
        }
        if (in_array($row->operation, ['create', 'notes']) && ($proof['result_id'] ?? 0) > 0) {
            if ((int) ($item['id'] ?? 0) === (int) $proof['result_id']) {
                return ['decision' => 'own', 'reason' => 'ID созданной записи совпадает с ответом amoCRM', 'intent_id' => $row->id];
            }

            return ['decision' => 'external', 'reason' => 'Создана другая запись'];
        }
        if (in_array($row->operation, ['link', 'unlink'])) {
            $range = $proof[$row->entity === ($event['entity'] ?? '') ? 'revision_range' : 'related_revision_range'] ?? [];
            [$before, $after] = array_pad($range, 2, null);
            if ($revision && $before && $after && $after >= $before && ($revision < $before || $revision > $after)) {
                return ['decision' => 'external', 'reason' => 'Версия вне интервала изменения связи'];
            }

            return $review;
        }
        if ($savedRevision && $revision && $revision !== $savedRevision) {
            return ['decision' => 'external', 'reason' => 'Другая версия сущности'];
        }
        if ($row->operation !== 'update' || ! ($proof['supported'] ?? false) || ! $revision || ! $savedRevision) {
            return $review;
        }
        $actual = WorkflowMutationEvidence::fields($item);
        $expected = $proof['expected'] ?? [];
        $before = $proof['before'] ?? [];
        if ($expected === [] || $before === []) {
            return $review;
        }
        foreach ($actual as $key => $value) {
            if (str_starts_with($key, 'cf:')) {
                continue;
            }
            if ((isset($expected[$key]) && $value !== $expected[$key])
                || (! isset($expected[$key]) && isset($before[$key]) && $value !== $before[$key])) {
                // amoCRM may normalize values or update dependent fields (e.g. closed_at).
                // A difference inside the same second is not proof of an independent actor.
                return [...$review, 'reason' => 'Значения отличаются, но время совпадает с нашим изменением. Источник требует проверки.'];
            }
        }

        // Matching state is not proof of origin: webhook revisions have second precision.
        // Two actors can make indistinguishable writes in that second. Never silently discard it.
        return $review;
    }
}

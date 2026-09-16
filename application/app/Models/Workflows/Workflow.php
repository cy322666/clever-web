<?php

namespace App\Models\Workflows;

use App\Workflows\Triggers\GenericWebhookTrigger;
use App\Workflows\Triggers\AmoCrmButtonTrigger;
use App\Services\Workflows\WorkflowSubscriptionAccess;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Leek\FilamentWorkflows\Enums\TriggerType;
use Leek\FilamentWorkflows\Models\Workflow as BaseWorkflow;

class Workflow extends BaseWorkflow
{
    protected static function booted(): void
    {
        parent::booted();

        static::saving(static function (Workflow $workflow): void {
            if ($workflow->is_active && (!$workflow->exists || $workflow->isDirty(['definition', 'is_active']))) {
                $issues = \App\Services\Workflows\WorkflowDefinitionValidator::issues($workflow->definition ?? []);
                if ($issues !== []) throw \Illuminate\Validation\ValidationException::withMessages(['definition' => $issues]);
            }
            if (array_key_exists('connections', $workflow->definition ?? [])) {
                try {
                    \App\Services\Workflows\WorkflowGraph::ordered($workflow->definition);
                } catch (\InvalidArgumentException $error) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['definition' => $error->getMessage()]);
                }
            }
            if ($workflow->is_active && ! static::definitionHasConfiguredActions($workflow->definition)) {
                $workflow->is_active = false;
            }

            if ($workflow->is_active) {
                $userId = (int) ($workflow->{config('filament-workflows.tenancy.column', 'user_id')} ?: Auth::id());
                $subscriptionIssue = app(WorkflowSubscriptionAccess::class)->activationIssue($userId);

                if ($subscriptionIssue !== null) {
                    if ($workflow->exists && ! $workflow->isDirty('is_active')) {
                        $workflow->is_active = false;
                    } else {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'is_active' => $subscriptionIssue,
                        ]);
                    }
                }
            }

            if ($workflow->is_active && ! \App\Services\Workflows\WorkflowConnectionAccess::hasActiveConnection(
                (int) ($workflow->{config('filament-workflows.tenancy.column', 'user_id')} ?: Auth::id())
            )) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'is_active' => 'Для включения сценария нужно активное подключение amoCRM к аккаунту платформы.',
                ]);
            }

            foreach (\App\Services\Workflows\WorkflowStartNodes::all($workflow->definition ?? []) as $start) {
                if ($workflow->is_active && static::activeDuplicateForUniqueTrigger(
                        (string) ($start['type'] ?? ''),
                        $workflow->account_id,
                        $workflow->user_id,
                        $workflow->exists ? $workflow->getKey() : null,
                    )) {
                    $workflow->is_active = false;
                }
            }
        });

        static::deleting(static function (Workflow $workflow): void {
            static::deleteRuntimeData($workflow->getKey());
        });
    }

    /**
     * @return array<int, string>
     */
    public function getFillable(): array
    {
        return array_values(array_unique([
            ...parent::getFillable(),
            'group_name',
        ]));
    }

    /**
     * @return array<string, string>
     */
    public static function groupOptions(): array
    {
        return \App\Services\Workflows\WorkflowFolders::options();
    }

    /**
     * @param array<string, mixed>|null $definition
     */
    public static function definitionHasConfiguredActions(?array $definition): bool
    {
        $actions = data_get($definition, 'actions', []);

        if (array_key_exists('connections', $definition ?? [])) {
            // An isolated draft tile is not an executable scenario.
            foreach (\App\Services\Workflows\WorkflowStartNodes::all($definition) as $id => $start) {
                if (\App\Services\Workflows\WorkflowGraph::targets($definition['connections'], $id) !== []) return true;
            }
            return false;
        }

        return is_array($actions) && static::actionListHasConfiguredAction($actions);
    }

    public static function requiresUniqueActiveTrigger(string $triggerType): bool
    {
        return str_starts_with($triggerType, 'amocrm-');
    }

    public function scopeWithStartType(\Illuminate\Database\Eloquent\Builder $query, string $type): \Illuminate\Database\Eloquent\Builder
    {
        // Indexed JSON paths work consistently in PostgreSQL and the isolated SQLite tests.
        return $query->where(function ($query) use ($type): void {
            $query->where('definition->trigger->type', $type);
            for ($index = 0; $index < 20; $index++) {
                $query->orWhere('definition->additional_triggers['.$index.']->type', $type);
            }
        });
    }

    public static function activeDuplicateForUniqueTrigger(
        string $triggerType,
        int|string|null $accountId = null,
        int|string|null $userId = null,
        int|string|null $exceptWorkflowId = null,
    ): ?self {
        if (!static::requiresUniqueActiveTrigger($triggerType)) {
            return null;
        }

        $query = static::query()
            ->where('is_active', true)
            ->withStartType($triggerType);

        if (filled($accountId)) {
            $query->where('account_id', $accountId);
        } elseif (filled($userId)) {
            $query->where('user_id', $userId);
        } else {
            return null;
        }

        if (filled($exceptWorkflowId)) {
            $query->whereKeyNot($exceptWorkflowId);
        }

        return $query->first(['id', 'name', 'definition']);
    }

    /**
     * @param array<int, mixed> $actions
     */
    private static function actionListHasConfiguredAction(array $actions): bool
    {
        foreach ($actions as $action) {
            if (! is_array($action)) {
                continue;
            }

            if (filled($action['type'] ?? null)) {
                return true;
            }

            foreach (['true_actions', 'false_actions'] as $branchKey) {
                $branchActions = data_get($action, 'config.' . $branchKey, []);

                if (is_array($branchActions) && static::actionListHasConfiguredAction($branchActions)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return array_replace(parent::casts(), [
            'failure_strategy' => 'string',
        ]);
    }

    protected static function getCurrentTenantId(): int|string|null
    {
        return Auth::id();
    }

    protected function syncTriggerMetadata(): void
    {
        parent::syncTriggerMetadata();

        if (data_get($this->definition, 'trigger.type') === AmoCrmButtonTrigger::type()) {
            $this->trigger_type = TriggerType::MANUAL;
            $this->trigger_event = null;
            $this->trigger_model_type = null;
            $this->trigger_schedule = null;
            $this->trigger_conditions = null;

            return;
        }

        if (!in_array(data_get($this->definition, 'trigger.type'), [GenericWebhookTrigger::type(), \App\Workflows\Triggers\DigitalPipelineTrigger::type()], true)) {
            return;
        }

        $this->trigger_type = TriggerType::WEBHOOK;
        $this->trigger_event = null;
        $this->trigger_model_type = null;
    }

    protected function groupName(): Attribute
    {
        return Attribute::make(
            set: static function (?string $value): ?string {
                $value = trim((string)$value);

                return $value !== '' ? $value : null;
            },
        );
    }

    private static function deleteRuntimeData(int|string $workflowId): void
    {
        if (!Schema::hasTable('workflow_runs')) {
            return;
        }

        DB::table('workflow_runs')
            ->where('workflow_id', $workflowId)
            ->select('id')
            ->orderBy('id')
            ->chunkById(1000, static function ($runs): void {
                $ids = $runs->pluck('id')->all();

                if ($ids === []) {
                    return;
                }

                if (Schema::hasTable('workflow_run_entities')) {
                    DB::table('workflow_run_entities')
                        ->whereIn('workflow_run_id', $ids)
                        ->delete();
                }

                if (Schema::hasTable('workflow_run_steps')) {
                    DB::table('workflow_run_steps')
                        ->whereIn('workflow_run_id', $ids)
                        ->delete();
                }
            });

        foreach (['workflow_amo_crm_mutations', 'workflow_metrics'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            DB::table($table)
                ->where('workflow_id', $workflowId)
                ->delete();
        }

        DB::table('workflow_runs')
            ->where('workflow_id', $workflowId)
            ->delete();
    }
}

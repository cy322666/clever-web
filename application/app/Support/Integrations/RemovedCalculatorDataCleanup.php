<?php

namespace App\Support\Integrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;
use stdClass;

final class RemovedCalculatorDataCleanup
{
    private const ACTION = 'amocrm_calculate_field';

    public function run(): void
    {
        DB::transaction(function (): void {
            $this->cleanDefinitions('workflows');
            $this->cleanDefinitions('workflow_templates');
            $this->cleanRuns();
            $this->deleteSteps();

            if ($this->hasColumns('workflow_amo_crm_mutations', ['action_type'])) {
                DB::table('workflow_amo_crm_mutations')->where('action_type', self::ACTION)->delete();
            }
        });
    }

    private function cleanDefinitions(string $table): void
    {
        if (!$this->hasColumns($table, ['id', 'definition'])) {
            return;
        }

        $hasActive = Schema::hasColumn($table, 'is_active');
        DB::table($table)->select(['id', 'definition'])->whereNotNull('definition')
            ->chunkById(100, function ($rows) use ($table, $hasActive): void {
                foreach ($rows as $row) {
                    $definition = $this->decode($row->definition);
                    $removedIds = [];

                    if (!$definition || !$this->cleanDefinition($definition, $removedIds)) {
                        continue;
                    }

                    $changes = ['definition' => $this->encode($definition)];
                    if ($hasActive) {
                        $changes['is_active'] = false;
                    }
                    DB::table($table)->where('id', $row->id)->update($changes);
                }
            });
    }

    private function cleanDefinition(stdClass $definition, array &$removedIds): bool
    {
        if (!is_array($definition->actions ?? null)) {
            return false;
        }

        $changed = false;
        $definition->actions = $this->cleanActions($definition->actions, $removedIds, $changed);

        if ($changed && is_array($definition->connections ?? null)) {
            $definition->connections = array_values(array_filter(
                $definition->connections,
                function ($edge) use ($removedIds): bool {
                    if (!$edge instanceof stdClass) {
                        return true;
                    }

                    foreach (['sourceId', 'targetId'] as $endpoint) {
                        $id = $edge->{$endpoint} ?? null;
                        if (is_string($id) && str_starts_with($id, 'action:') && isset($removedIds[substr($id, 7)])) {
                            return false;
                        }
                    }

                    return true;
                },
            ));
        }

        return $changed;
    }

    private function cleanActions(array $actions, array &$removedIds, bool &$changed): array
    {
        $kept = [];
        foreach ($actions as $action) {
            if ($action instanceof stdClass && ($action->type ?? null) === self::ACTION) {
                if ((is_string($action->id ?? null) || is_int($action->id ?? null)) && (string)$action->id !== '') {
                    $removedIds[(string)$action->id] = true;
                }
                $changed = true;
                continue;
            }

            if ($action instanceof stdClass) {
                // Match the runtime's legacy fallback without searching arbitrary payload JSON.
                $config = $action->config ?? $action->properties ?? null;
                if ($config instanceof stdClass) {
                    foreach (['true_actions', 'false_actions'] as $branch) {
                        if (is_array($config->{$branch} ?? null)) {
                            $config->{$branch} = $this->cleanActions($config->{$branch}, $removedIds, $changed);
                        }
                    }
                }
            }

            $kept[] = $action;
        }

        return $kept;
    }

    private function cleanRuns(): void
    {
        if (!$this->hasColumns('workflow_runs', ['id', 'context_data'])) {
            return;
        }

        $hasStatus = Schema::hasColumn('workflow_runs', 'status');
        $hasResume = Schema::hasColumn('workflow_runs', 'scheduled_resume_at');
        $hasSteps = $this->hasColumns('workflow_run_steps', ['workflow_run_id', 'step_id', 'action_type']);
        $columns = $hasStatus ? ['id', 'context_data', 'status'] : ['id', 'context_data'];

        DB::table('workflow_runs')->select($columns)
            ->chunkById(100, function ($rows) use ($hasStatus, $hasResume, $hasSteps): void {
                $idsByRun = [];
                if ($hasSteps) {
                    $steps = DB::table('workflow_run_steps')->whereIn('workflow_run_id', $rows->pluck('id'))
                        ->where('action_type', self::ACTION)->get(['workflow_run_id', 'step_id']);
                    foreach ($steps as $step) {
                        if (is_string($step->step_id) && $step->step_id !== '') {
                            $idsByRun[$step->workflow_run_id][$step->step_id] = true;
                        }
                    }
                }

                foreach ($rows as $row) {
                    // Historical node identity comes from this run, never today's edited workflow.
                    $removedIds = $idsByRun[$row->id] ?? [];
                    $affected = $removedIds !== [];
                    $context = $this->decode($row->context_data);
                    $changes = [];

                    if ($context) {
                        $before = $this->encode($context);
                        $variables = $context->variables ?? null;
                        $snapshot = $variables instanceof stdClass ? ($variables->_definition_snapshot ?? null) : null;
                        if ($snapshot instanceof stdClass && $this->cleanDefinition($snapshot, $removedIds)) {
                            $affected = true;
                        }

                        $this->removeOutputKeys($context->step_outputs ?? null, $removedIds);
                        if ($variables instanceof stdClass) {
                            $this->removeOutputKeys($variables->_resolved_inputs ?? null, $removedIds);
                            $this->cleanNodeNames($variables->_node_names ?? null, $removedIds);
                        }

                        $after = $this->encode($context);
                        if ($before !== $after) {
                            $changes['context_data'] = $after;
                        }
                    }

                    if ($affected && $hasStatus && in_array($row->status, ['pending', 'running', 'paused'], true)) {
                        $changes['status'] = 'cancelled';
                        if ($hasResume) {
                            $changes['scheduled_resume_at'] = null;
                        }
                    }

                    if ($changes !== []) {
                        DB::table('workflow_runs')->where('id', $row->id)->update($changes);
                    }
                }
            });
    }

    private function removeOutputKeys(mixed $outputs, array $removedIds): void
    {
        if (!$outputs instanceof stdClass) {
            return;
        }

        foreach ($removedIds as $id => $_) {
            unset($outputs->{$id});
        }
    }

    private function cleanNodeNames(mixed $names, array $removedIds): void
    {
        if (!$names instanceof stdClass || $removedIds === []) {
            return;
        }

        foreach ($names as $name => $ids) {
            if (!is_array($ids)) {
                continue;
            }
            $kept = array_values(array_filter($ids, fn ($id): bool => !is_scalar($id) || !isset($removedIds[(string)$id])));
            if ($kept === $ids) {
                continue;
            }
            if ($kept === []) {
                unset($names->{$name});
            } else {
                $names->{$name} = $kept;
            }
        }
    }

    private function deleteSteps(): void
    {
        if (!$this->hasColumns('workflow_run_steps', ['id', 'action_type'])) {
            return;
        }

        if ($this->hasColumns('workflow_run_entities', ['workflow_run_step_id'])) {
            DB::table('workflow_run_entities')->whereIn('workflow_run_step_id',
                DB::table('workflow_run_steps')->select('id')->where('action_type', self::ACTION),
            )->delete();
        }

        DB::table('workflow_run_steps')->where('action_type', self::ACTION)->delete();
    }

    private function hasColumns(string $table, array $columns): bool
    {
        if (!Schema::hasTable($table)) {
            return false;
        }

        foreach ($columns as $column) {
            if (!Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    private function decode(mixed $value): ?stdClass
    {
        if ($value instanceof stdClass) {
            return $value;
        }
        if (!is_string($value)) {
            return null;
        }

        try {
            $decoded = json_decode($value, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return $decoded instanceof stdClass ? $decoded : null;
    }

    private function encode(stdClass $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

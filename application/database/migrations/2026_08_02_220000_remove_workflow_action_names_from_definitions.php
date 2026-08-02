<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->cleanDefinitions('workflows', 'definition');

        if (Schema::hasTable('workflow_templates')) {
            $this->cleanDefinitions('workflow_templates', 'definition');
        }
    }

    public function down(): void
    {
        // Action notes were stored only as optional JSON metadata and cannot be restored.
    }

    private function cleanDefinitions(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        DB::table($table)
            ->select('id', $column)
            ->whereNotNull($column)
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($table, $column): void {
                foreach ($rows as $row) {
                    $definition = $this->normalizeJson($row->{$column});

                    if (! is_array($definition)) {
                        continue;
                    }

                    $actions = $definition['actions'] ?? null;

                    if (! is_array($actions)) {
                        continue;
                    }

                    $changed = false;
                    $definition['actions'] = $this->removeActionNames($actions, $changed);

                    if (! $changed) {
                        continue;
                    }

                    DB::table($table)
                        ->where('id', $row->id)
                        ->update([
                            $column => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ]);
                }
            });
    }

    private function normalizeJson(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return json_decode(json_encode($value), true);
        }

        if (is_string($value) && $value !== '') {
            return json_decode($value, true);
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $actions
     * @return array<int, array<string, mixed>>
     */
    private function removeActionNames(array $actions, bool &$changed): array
    {
        foreach ($actions as &$action) {
            if (! is_array($action)) {
                continue;
            }

            if (array_key_exists('name', $action)) {
                unset($action['name']);
                $changed = true;
            }

            foreach (['true_actions', 'false_actions'] as $branch) {
                if (isset($action['config'][$branch]) && is_array($action['config'][$branch])) {
                    $action['config'][$branch] = $this->removeActionNames($action['config'][$branch], $changed);
                }
            }
        }

        return $actions;
    }
};

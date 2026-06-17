<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->createIndex('workflow_runs', 'workflow_runs_user_triggerable_id_idx',
            'CREATE INDEX IF NOT EXISTS workflow_runs_user_triggerable_id_idx ON workflow_runs (user_id, triggerable_id)');

        $this->createIndex('workflow_runs', 'workflow_runs_workflow_id_id_idx',
            'CREATE INDEX IF NOT EXISTS workflow_runs_workflow_id_id_idx ON workflow_runs (workflow_id, id DESC)');

        $this->createIndex('workflow_runs', 'workflow_runs_usage_pending_created_id_idx',
            'CREATE INDEX IF NOT EXISTS workflow_runs_usage_pending_created_id_idx ON workflow_runs (created_at, id) WHERE usage_recorded_at IS NULL');

        $this->createIndex('workflow_runs', 'workflow_runs_context_created_id_idx',
            'CREATE INDEX IF NOT EXISTS workflow_runs_context_created_id_idx ON workflow_runs (created_at, id) WHERE context_data IS NOT NULL');

        $this->createIndex('workflow_runs', 'workflow_runs_prunable_created_id_idx',
            "CREATE INDEX IF NOT EXISTS workflow_runs_prunable_created_id_idx ON workflow_runs (created_at, id) WHERE usage_recorded_at IS NOT NULL AND status NOT IN ('running', 'pending', 'paused')");

        $this->createIndex('workflow_run_steps', 'workflow_run_steps_run_id_id_idx',
            'CREATE INDEX IF NOT EXISTS workflow_run_steps_run_id_id_idx ON workflow_run_steps (workflow_run_id, id DESC)');

        $this->createIndex('workflow_run_steps', 'workflow_run_steps_raw_created_id_idx',
            'CREATE INDEX IF NOT EXISTS workflow_run_steps_raw_created_id_idx ON workflow_run_steps (created_at, id) WHERE input_data IS NOT NULL OR output_data IS NOT NULL');

        $this->createIndex('workflow_amo_crm_mutations', 'workflow_amo_mutations_guard_lookup_idx',
            'CREATE INDEX IF NOT EXISTS workflow_amo_mutations_guard_lookup_idx ON workflow_amo_crm_mutations (account_id, user_id, event, entity_type, entity_id, expires_at, id DESC)');

        $this->createIndex('accounts', 'accounts_active_lower_subdomain_widget_idx',
            'CREATE INDEX IF NOT EXISTS accounts_active_lower_subdomain_widget_idx ON accounts (lower(subdomain), widget, user_id, id DESC) WHERE active = true AND subdomain IS NOT NULL');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'workflow_runs_user_triggerable_id_idx',
            'workflow_runs_workflow_id_id_idx',
            'workflow_runs_usage_pending_created_id_idx',
            'workflow_runs_context_created_id_idx',
            'workflow_runs_prunable_created_id_idx',
            'workflow_run_steps_run_id_id_idx',
            'workflow_run_steps_raw_created_id_idx',
            'workflow_amo_mutations_guard_lookup_idx',
            'accounts_active_lower_subdomain_widget_idx',
        ] as $index) {
            DB::statement('DROP INDEX IF EXISTS ' . $index);
        }
    }

    private function createIndex(string $table, string $index, string $sql): void
    {
        if (!Schema::hasTable($table) || $this->indexExists($index)) {
            return;
        }

        DB::statement($sql);
    }

    private function indexExists(string $index): bool
    {
        return DB::table('pg_indexes')
            ->where('schemaname', DB::raw('current_schema()'))
            ->where('indexname', $index)
            ->exists();
    }
};

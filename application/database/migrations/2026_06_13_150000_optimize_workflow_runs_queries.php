<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('workflow_run_entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index()->constrained('users')->cascadeOnDelete();
            $table->foreignId('workflow_id')->index()->constrained('workflows')->cascadeOnDelete();
            $table->foreignId('workflow_run_id')->index()->constrained('workflow_runs')->cascadeOnDelete();
            $table->foreignId('workflow_run_step_id')->nullable()->index()->constrained('workflow_run_steps')->nullOnDelete();
            $table->string('entity_type', 32);
            $table->unsignedBigInteger('entity_id');
            $table->string('source', 32)->default('step');
            $table->string('url')->nullable();
            $table->timestamps();

            $table->unique([
                'workflow_run_id',
                'workflow_run_step_id',
                'entity_type',
                'entity_id',
                'source',
            ], 'workflow_run_entities_unique');
            $table->index(['user_id', 'entity_id'], 'workflow_run_entities_user_entity_id');
            $table->index(['user_id', 'entity_type', 'entity_id'], 'workflow_run_entities_user_type_entity');
            $table->index(['workflow_id', 'created_at'], 'workflow_run_entities_workflow_created');
        });

        Schema::table('workflow_runs', function (Blueprint $table) {
            $tenantColumn = config('filament-workflows.tenancy.column', 'user_id');

            if (Schema::hasColumn('workflow_runs', $tenantColumn)) {
                $table->index([$tenantColumn, 'created_at'], 'workflow_runs_tenant_created');
                $table->index([$tenantColumn, 'status', 'created_at'], 'workflow_runs_tenant_status_created');
                $table->index([$tenantColumn, 'trigger_source', 'created_at'], 'workflow_runs_tenant_trigger_created');
                $table->index([$tenantColumn, 'status', 'completed_at'], 'workflow_runs_tenant_status_completed');
            }

            $table->index(['workflow_id', 'created_at'], 'workflow_runs_workflow_created');
            $table->index(['workflow_id', 'status', 'completed_at'], 'workflow_runs_workflow_status_completed');
        });

        Schema::table('workflows', function (Blueprint $table) {
            $tenantColumn = config('filament-workflows.tenancy.column', 'user_id');

            if (Schema::hasColumn('workflows', $tenantColumn)) {
                $table->index([$tenantColumn, 'updated_at'], 'workflows_tenant_updated');
                $table->index([$tenantColumn, 'is_active', 'updated_at'], 'workflows_tenant_active_updated');
                $table->index([$tenantColumn, 'group_name'], 'workflows_tenant_group');
            }
        });

        Schema::table('workflow_metrics', function (Blueprint $table) {
            $tenantColumn = config('filament-workflows.tenancy.column', 'user_id');

            if (Schema::hasColumn('workflow_metrics', $tenantColumn)) {
                $table->index([$tenantColumn, 'workflow_id', 'period_type'], 'workflow_metrics_tenant_workflow_period');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workflow_metrics', function (Blueprint $table) {
            $tenantColumn = config('filament-workflows.tenancy.column', 'user_id');

            if (Schema::hasColumn('workflow_metrics', $tenantColumn)) {
                $table->dropIndex('workflow_metrics_tenant_workflow_period');
            }
        });

        Schema::table('workflows', function (Blueprint $table) {
            $tenantColumn = config('filament-workflows.tenancy.column', 'user_id');

            if (Schema::hasColumn('workflows', $tenantColumn)) {
                $table->dropIndex('workflows_tenant_group');
                $table->dropIndex('workflows_tenant_active_updated');
                $table->dropIndex('workflows_tenant_updated');
            }
        });

        Schema::table('workflow_runs', function (Blueprint $table) {
            $table->dropIndex('workflow_runs_workflow_status_completed');
            $table->dropIndex('workflow_runs_workflow_created');

            $tenantColumn = config('filament-workflows.tenancy.column', 'user_id');

            if (Schema::hasColumn('workflow_runs', $tenantColumn)) {
                $table->dropIndex('workflow_runs_tenant_status_completed');
                $table->dropIndex('workflow_runs_tenant_trigger_created');
                $table->dropIndex('workflow_runs_tenant_status_created');
                $table->dropIndex('workflow_runs_tenant_created');
            }
        });

        Schema::dropIfExists('workflow_run_entities');
    }
};

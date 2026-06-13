<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('workflow_amo_crm_mutations')) {
            Schema::create('workflow_amo_crm_mutations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->index()->constrained('users')->cascadeOnDelete();
                $table->foreignId('account_id')->index()->constrained('accounts')->cascadeOnDelete();
                $table->foreignId('workflow_id')->index()->constrained('workflows')->cascadeOnDelete();
                $table->foreignId('workflow_run_id')->nullable()->index()->constrained('workflow_runs')->nullOnDelete();
                $table->string('action_type')->nullable();
                $table->string('entity_type', 64);
                $table->unsignedBigInteger('entity_id');
                $table->string('event', 128);
                $table->string('chain_id')->nullable()->index();
                $table->timestamp('expires_at')->index();
                $table->timestamps();

                $table->index(
                    ['account_id', 'entity_type', 'entity_id', 'event', 'expires_at'],
                    'workflow_amo_mutations_lookup'
                );
                $table->index(['user_id', 'expires_at'], 'workflow_amo_mutations_user_expires');
            });
        }

        if (!Schema::hasTable('workflow_usage_months')) {
            Schema::create('workflow_usage_months', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->index()->constrained('users')->cascadeOnDelete();
                $table->unsignedBigInteger('workflow_id')->index();
                $table->date('period_month');
                $table->unsignedBigInteger('runs_count')->default(0);
                $table->unsignedBigInteger('steps_count')->default(0);
                $table->unsignedBigInteger('actions_count')->default(0);
                $table->unsignedBigInteger('completed_runs_count')->default(0);
                $table->unsignedBigInteger('failed_runs_count')->default(0);
                $table->timestamp('last_aggregated_at')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'workflow_id', 'period_month'], 'workflow_usage_months_unique');
            });
        }

        if (Schema::hasTable('workflow_runs') && !Schema::hasColumn('workflow_runs', 'usage_recorded_at')) {
            Schema::table('workflow_runs', function (Blueprint $table) {
                $table->timestamp('usage_recorded_at')->nullable()->index()->after('completed_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('workflow_runs') && Schema::hasColumn('workflow_runs', 'usage_recorded_at')) {
            Schema::table('workflow_runs', function (Blueprint $table) {
                $table->dropColumn('usage_recorded_at');
            });
        }

        Schema::dropIfExists('workflow_usage_months');
        Schema::dropIfExists('workflow_amo_crm_mutations');
    }
};

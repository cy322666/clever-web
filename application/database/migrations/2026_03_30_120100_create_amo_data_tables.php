<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('amo_data_settings', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('active')->default(false);
            $table->json('settings')->nullable();

            $table->string('sync_status')->nullable();
            $table->timestamp('initial_synced_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('last_successful_sync_at')->nullable();
            $table->timestamp('leads_synced_at')->nullable();
            $table->timestamp('tasks_synced_at')->nullable();
            $table->unsignedInteger('last_leads_count')->default(0);
            $table->unsignedInteger('last_tasks_count')->default(0);
            $table->unsignedInteger('last_events_count')->default(0);
            $table->text('last_error')->nullable();

            $table->unique('user_id');
        });

        Schema::create('amocrm_leads', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('amocrm_status_id')->nullable()->constrained('amocrm_statuses')->nullOnDelete();
            $table->foreignId('amocrm_staff_id')->nullable()->constrained('amocrm_staffs')->nullOnDelete();

            $table->unsignedBigInteger('amo_id');
            $table->string('name')->nullable();
            $table->integer('pipeline_id')->nullable()->index();
            $table->integer('status_id')->nullable()->index();
            $table->integer('responsible_user_id')->nullable()->index();
            $table->bigInteger('price')->nullable();
            $table->timestamp('amo_created_at')->nullable();
            $table->timestamp('amo_updated_at')->nullable()->index();
            $table->timestamp('closed_at')->nullable();
            $table->boolean('is_closed')->default(false)->index();
            $table->boolean('is_won')->default(false)->index();
            $table->boolean('is_lost')->default(false)->index();
            $table->json('payload')->nullable();

            $table->unique(['user_id', 'amo_id']);
        });

        Schema::create('amocrm_tasks', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('amocrm_staff_id')->nullable()->constrained('amocrm_staffs')->nullOnDelete();

            $table->unsignedBigInteger('amo_id');
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable()->index();
            $table->integer('responsible_user_id')->nullable()->index();
            $table->integer('type_id')->nullable();
            $table->text('text')->nullable();
            $table->timestamp('complete_till')->nullable();
            $table->boolean('is_completed')->default(false)->index();
            $table->timestamp('amo_created_at')->nullable();
            $table->timestamp('amo_updated_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->json('payload')->nullable();

            $table->unique(['user_id', 'amo_id']);
        });

        Schema::create('amocrm_events', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('amocrm_leads')->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('amocrm_tasks')->nullOnDelete();

            $table->string('entity_type', 32)->index();
            $table->unsignedBigInteger('entity_amo_id')->index();
            $table->string('event_type', 64)->index();
            $table->string('event_key')->index();
            $table->timestamp('event_at')->nullable()->index();
            $table->integer('from_pipeline_id')->nullable();
            $table->integer('to_pipeline_id')->nullable();
            $table->integer('from_status_id')->nullable();
            $table->integer('to_status_id')->nullable();
            $table->integer('from_responsible_user_id')->nullable();
            $table->integer('to_responsible_user_id')->nullable();
            $table->json('meta')->nullable();

            $table->unique(['user_id', 'event_key']);
        });

        Schema::create('amocrm_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('setting_id')->constrained('amo_data_settings')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();

            $table->string('type', 32);
            $table->string('status', 32);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('leads_synced')->default(0);
            $table->unsignedInteger('tasks_synced')->default(0);
            $table->unsignedInteger('events_created')->default(0);
            $table->text('error')->nullable();
            $table->json('meta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amocrm_sync_runs');
        Schema::dropIfExists('amocrm_events');
        Schema::dropIfExists('amocrm_tasks');
        Schema::dropIfExists('amocrm_leads');
        Schema::dropIfExists('amo_data_settings');
    }
};

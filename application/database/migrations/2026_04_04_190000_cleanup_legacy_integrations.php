<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        DB::table('apps')
            ->whereIn('name', ['marquiz', 'create-lead', 'triggers'])
            ->orWhere('resource_name', 'like', '%Marquiz%')
            ->orWhere('resource_name', 'like', '%Triggers%')
            ->delete();

        Schema::dropIfExists('marquiz_forms');
        Schema::dropIfExists('marquiz_settings');
        Schema::dropIfExists('create_lead_transactions');
        Schema::dropIfExists('create_lead_settings');
        Schema::dropIfExists('trigger_events');
        Schema::dropIfExists('trigger_settings');
    }

    public function down(): void
    {
        if (!Schema::hasTable('marquiz_settings')) {
            Schema::create('marquiz_settings', function (Blueprint $table) {
                $table->id();
                $table->timestamps();

                $table->json('settings')->nullable();
                $table->boolean('active')->default(false);
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('marquiz_forms')) {
            Schema::create('marquiz_forms', function (Blueprint $table) {
                $table->id();
                $table->timestamps();

                $table->integer('lead_id')->nullable();
                $table->integer('contact_id')->nullable();
                $table->json('body')->nullable();
                $table->integer('quiz')->nullable();
                $table->string('name')->nullable();
                $table->boolean('status')->default(false);
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('create_lead_settings')) {
            Schema::create('create_lead_settings', function (Blueprint $table) {
                $table->id();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('create_lead_transactions')) {
            Schema::create('create_lead_transactions', function (Blueprint $table) {
                $table->id();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('trigger_settings')) {
            Schema::create('trigger_settings', function (Blueprint $table) {
                $table->id();
                $table->timestamps();

                $table->string('trigger');
                $table->string('name');
                $table->integer('parent_trigger_id')->nullable();
                $table->json('conditions')->nullable();
                $table->json('actions');
                $table->boolean('active')->default(false);
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('trigger_events')) {
            Schema::create('trigger_events', function (Blueprint $table) {
                $table->id();
                $table->timestamps();

                $table->unsignedBigInteger('event_id')->nullable();
                $table->string('type')->nullable();
                $table->unsignedBigInteger('entity_id')->nullable();
                $table->string('entity_type')->nullable();
                $table->unsignedBigInteger('event_created_by')->nullable();
                $table->unsignedBigInteger('event_created_at')->nullable();
                $table->text('value_after')->nullable();
                $table->text('value_before')->nullable();
                $table->unsignedBigInteger('event_account_id')->nullable();
                $table->unsignedBigInteger('account_id')->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            });
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sqns_settings', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
            $table->boolean('active')->default(false);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('api_base_url')->default('https://crm3.sqns.ru');
            $table->string('email')->nullable();
            $table->text('password')->nullable();
            $table->text('token')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->string('webhook_key', 64)->unique();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('organization_name')->nullable();
            $table->json('pipelines')->nullable();
            $table->string('status_id_cancel', 20)->nullable();
            $table->string('status_id_wait', 20)->nullable();
            $table->string('status_id_came', 20)->nullable();
            $table->string('status_id_confirm', 20)->nullable();
            $table->string('status_id_delete', 20)->nullable();
            $table->unsignedBigInteger('default_responsible_user_id')->nullable();
            $table->json('fields_contact')->nullable();
            $table->json('fields_lead')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();

            $table->unique(['user_id', 'account_id']);
        });

        Schema::create('sqns_clients', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('setting_id')->constrained('sqns_settings')->cascadeOnDelete();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->string('additional_phone')->nullable();
            $table->string('email')->nullable();
            $table->date('birth_date')->nullable();
            $table->unsignedTinyInteger('sex')->nullable();
            $table->unsignedInteger('visits_count')->nullable();
            $table->decimal('total_arrival', 14, 2)->nullable();
            $table->json('tags')->nullable();
            $table->json('body')->nullable();

            $table->unique(['setting_id', 'client_id']);
            $table->index(['account_id', 'contact_id']);
        });

        Schema::create('sqns_visits', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('setting_id')->constrained('sqns_settings')->cascadeOnDelete();
            $table->unsignedBigInteger('visit_id');
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('organization_name')->nullable();
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->dateTime('datetime')->nullable();
            $table->decimal('cost', 14, 2)->nullable();
            $table->smallInteger('attendance')->nullable();
            $table->boolean('deleted')->default(false);
            $table->boolean('online')->nullable();
            $table->boolean('is_paid')->nullable();
            $table->string('author')->nullable();
            $table->text('services')->nullable();
            $table->text('comment')->nullable();
            $table->dateTime('source_created_at')->nullable();
            $table->dateTime('source_updated_at')->nullable();
            $table->string('status', 20)->nullable();
            $table->text('error_message')->nullable();
            $table->json('body')->nullable();

            $table->unique(['setting_id', 'visit_id']);
            $table->index(['account_id', 'lead_id']);
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sqns_visits');
        Schema::dropIfExists('sqns_clients');
        Schema::dropIfExists('sqns_settings');
    }
};

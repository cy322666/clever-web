<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vetmanager_settings', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
            $table->boolean('active')->default(false);
            $table->string('base_url')->nullable();
            $table->text('api_key')->nullable();
            $table->string('timezone')->default('Europe/Moscow');
            $table->text('webhook_secret');
            $table->string('webhook_id')->nullable();
            $table->timestamp('webhook_synced_at')->nullable();
            $table->string('target_status')->nullable();
            $table->unsignedBigInteger('responsible_user_id')->nullable();
            $table->boolean('sync_price')->default(true);
            $table->unsignedBigInteger('contact_external_id_field_id')->nullable();
            $table->unsignedBigInteger('lead_external_id_field_id')->nullable();
            $table->unsignedBigInteger('lead_admission_date_field_id')->nullable();
            $table->unsignedBigInteger('lead_patient_name_field_id')->nullable();
            $table->unsignedBigInteger('lead_doctor_name_field_id')->nullable();
            $table->unsignedBigInteger('lead_description_field_id')->nullable();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();

            $table->unique(['user_id', 'account_id'], 'vetmanager_settings_user_account_unique');
        });

        Schema::create('vetmanager_clients', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
            $table->string('external_id');
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('setting_id')->constrained('vetmanager_settings')->cascadeOnDelete();

            $table->unique(['setting_id', 'external_id'], 'vetmanager_clients_setting_external_unique');
            $table->index(['account_id', 'contact_id']);
        });

        Schema::create('vetmanager_visits', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
            $table->string('external_id');
            $table->string('event_name')->nullable();
            $table->string('status')->default('pending');
            $table->string('vetmanager_status')->nullable();
            $table->string('client_id')->nullable();
            $table->string('patient_id')->nullable();
            $table->string('clinic_id')->nullable();
            $table->string('doctor_id')->nullable();
            $table->string('client_name')->nullable();
            $table->string('patient_name')->nullable();
            $table->string('doctor_name')->nullable();
            $table->dateTime('admission_date')->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->json('event_payload')->nullable();
            $table->json('admission_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('setting_id')->constrained('vetmanager_settings')->cascadeOnDelete();

            $table->unique(['setting_id', 'external_id'], 'vetmanager_visits_setting_external_unique');
            $table->index(['user_id', 'status']);
            $table->index(['account_id', 'lead_id']);
            $table->index(['setting_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vetmanager_visits');
        Schema::dropIfExists('vetmanager_clients');
        Schema::dropIfExists('vetmanager_settings');
    }
};

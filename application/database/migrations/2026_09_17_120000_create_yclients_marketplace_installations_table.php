<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('yclients_marketplace_installations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('setting_id')->nullable()->constrained('yclients_settings')->nullOnDelete();
            $table->unsignedBigInteger('salon_id');
            $table->unsignedBigInteger('application_id');
            $table->string('status', 32)->default('active')->index();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->json('last_payload')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['application_id', 'salon_id'], 'yclients_marketplace_app_salon_unique');
            $table->index(['user_id', 'status'], 'yclients_marketplace_user_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('yclients_marketplace_installations');
    }
};

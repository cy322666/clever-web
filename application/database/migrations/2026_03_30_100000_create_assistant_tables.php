<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('assistant_settings', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->json('settings')->nullable();
            $table->boolean('active')->default(false);
            $table->string('service_token', 120)->nullable();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        });

        Schema::create('assistant_chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('assistant_setting_id')->constrained('assistant_settings')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();

            $table->string('title')->nullable();
            $table->string('source')->default('chat');
            $table->string('status')->default('active');
            $table->string('external_id')->nullable();
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->string('staff_name')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('last_message_at')->nullable();
        });

        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('session_id')->constrained('assistant_chat_sessions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('role', 32);
            $table->string('status')->default('created');
            $table->string('external_id')->nullable();
            $table->string('model')->nullable();
            $table->string('prompt_version')->nullable();
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->longText('content')->nullable();
            $table->json('tool_calls')->nullable();
            $table->json('meta')->nullable();
        });

        Schema::create('assistant_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('assistant_setting_id')->constrained('assistant_settings')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('assistant_chat_sessions')->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('assistant_messages')->nullOnDelete();

            $table->string('source')->default('api');
            $table->string('status')->default('success');
            $table->string('endpoint')->nullable();
            $table->string('tool')->nullable();
            $table->string('model')->nullable();
            $table->string('prompt_version')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_logs');
        Schema::dropIfExists('assistant_messages');
        Schema::dropIfExists('assistant_chat_sessions');
        Schema::dropIfExists('assistant_settings');
    }
};

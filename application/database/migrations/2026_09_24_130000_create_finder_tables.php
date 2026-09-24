<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finder_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->boolean('active')->default(false);
            $table->boolean('enabled')->default(false);
            $table->json('settings')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_webhook_at')->nullable();
            $table->timestamps();
        });
        Schema::create('finder_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('setting_id')->constrained('finder_settings')->cascadeOnDelete();
            $table->string('chat_id', 191);
            $table->unsignedBigInteger('talk_id')->nullable();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_outgoing_at')->nullable();
            $table->timestamp('pending_since')->nullable();
            $table->timestamp('next_check_at')->nullable()->index();
            $table->unsignedInteger('cycle')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
            $table->unique(['setting_id', 'chat_id']);
        });
        Schema::create('finder_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('finder_conversations')->cascadeOnDelete();
            $table->string('message_id', 191);
            $table->string('direction', 10);
            $table->timestamp('sent_at');
            $table->unique(['conversation_id', 'message_id', 'direction'], 'finder_messages_unique');
            $table->index(['conversation_id', 'direction', 'sent_at'], 'finder_messages_timeline');
        });
        Schema::create('finder_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('setting_id')->constrained('finder_settings')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('finder_conversations')->cascadeOnDelete();
            $table->unsignedInteger('cycle');
            $table->unsignedInteger('attempt');
            $table->string('event', 20);
            $table->string('kind', 20);
            $table->string('status', 20)->default('pending')->index();
            $table->json('payload');
            $table->unsignedBigInteger('result_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'cycle', 'attempt', 'event', 'kind'], 'finder_actions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finder_actions');
        Schema::dropIfExists('finder_messages');
        Schema::dropIfExists('finder_conversations');
        Schema::dropIfExists('finder_settings');
    }
};

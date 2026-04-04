<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_threads', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->string('thread_id')->unique();
            $table->uuid('user_uuid')->index();
            $table->string('channel')->nullable();
            $table->text('summary')->default('');
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('thread_ref_id')
                ->constrained('ai_threads')
                ->cascadeOnDelete();

            $table->string('thread_id')->index();
            $table->string('role', 32);
            $table->text('content');
            $table->json('meta')->nullable();
            $table->timestamp('message_at')->nullable()->index();

            $table->index(['thread_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_threads');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('assistant_logs')) {
            Schema::table('assistant_logs', function (Blueprint $table) {
                if (Schema::hasColumn('assistant_logs', 'session_id')) {
                    $table->dropConstrainedForeignId('session_id');
                }

                if (Schema::hasColumn('assistant_logs', 'message_id')) {
                    $table->dropConstrainedForeignId('message_id');
                }
            });
        }

        if (Schema::hasTable('assistant_messages')) {
            Schema::drop('assistant_messages');
        }

        if (Schema::hasTable('assistant_chat_sessions')) {
            Schema::drop('assistant_chat_sessions');
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('assistant_chat_sessions')) {
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
        }

        if (!Schema::hasTable('assistant_messages')) {
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
        }

        if (Schema::hasTable('assistant_logs')) {
            Schema::table('assistant_logs', function (Blueprint $table) {
                if (!Schema::hasColumn('assistant_logs', 'session_id')) {
                    $table->foreignId('session_id')->nullable()->constrained('assistant_chat_sessions')->nullOnDelete();
                }

                if (!Schema::hasColumn('assistant_logs', 'message_id')) {
                    $table->foreignId('message_id')->nullable()->constrained('assistant_messages')->nullOnDelete();
                }
            });
        }
    }
};

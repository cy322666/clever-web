<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('calculator_transactions', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('calculator_setting_id')->nullable()->constrained('calculator_settings')->nullOnDelete();
            $table->unsignedBigInteger('workflow_id')->nullable();

            $table->string('entity_type', 32)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('field_id')->nullable();
            $table->string('field_name')->nullable();

            $table->text('expression')->nullable();
            $table->string('result_value')->nullable();
            $table->unsignedTinyInteger('status')->default(0);
            $table->text('error_message')->nullable();
            $table->json('payload')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index(['account_id', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calculator_transactions');
    }
};

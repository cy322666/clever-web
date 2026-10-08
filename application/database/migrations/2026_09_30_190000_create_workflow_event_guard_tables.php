<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_write_intents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('account_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('workflow_id');
            $t->unsignedBigInteger('run_id')->nullable();
            $t->string('entity', 32);
            $t->unsignedBigInteger('entity_id')->default(0);
            $t->string('related_entity', 32)->nullable();
            $t->unsignedBigInteger('related_id')->nullable();
            $t->string('operation', 32);
            $t->string('status', 24)->default('pending');
            $t->longText('evidence');
            $t->timestamps();
            $t->index(['account_id', 'user_id', 'entity', 'entity_id', 'created_at'], 'workflow_write_intent_lookup');
            $t->index(['account_id', 'related_entity', 'related_id'], 'workflow_write_intent_related');
        });
        Schema::create('workflow_event_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('account_id')->constrained()->cascadeOnDelete();
            $t->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $t->string('start_id');
            $t->char('fingerprint', 64)->nullable()->unique();
            $t->string('event', 128);
            $t->unsignedBigInteger('entity_id')->default(0);
            $t->string('status', 24)->default('review');
            $t->string('reason', 255);
            $t->longText('envelope');
            $t->timestamps();
            $t->index(['user_id', 'workflow_id', 'status'], 'workflow_event_review_owner');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_event_reviews');
        Schema::dropIfExists('workflow_write_intents');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('yclients_record_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('record_id');
            $table->json('payload');
            $table->json('source_snapshot');
            $table->json('record_snapshot');
            $table->json('client_snapshot')->nullable();
            $table->boolean('create_note')->default(false);
            $table->string('status', 16)->default('0');
            $table->text('error_message')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['record_id', 'status', 'id'], 'yclients_events_record_status');
            $table->index(['status', 'available_at', 'dispatched_at'], 'yclients_events_outbox');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('yclients_record_events');
    }
};

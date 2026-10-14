<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('yclients_create_receipts', function (Blueprint $table): void {
            $table->string('key', 64)->primary();
            $table->unsignedBigInteger('account_id')->index();
            $table->string('entity', 16);
            $table->unsignedBigInteger('remote_id')->nullable();
            $table->unsignedBigInteger('started_at');
            $table->string('identity_hash', 64)->nullable();
            $table->boolean('rejected')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('yclients_create_receipts');
    }
};

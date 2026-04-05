<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('api_requests', function (Blueprint $table) {
            $table->id();
            $table->string('method', 10);
            $table->string('path', 255);
            $table->string('route_name')->nullable();
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('user_uuid', 36)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->json('query_params')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index('method');
            $table->index('status_code');
            $table->index('user_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_requests');
    }
};

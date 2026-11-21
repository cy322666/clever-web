<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('yclients_marketplace_installations', function (Blueprint $table): void {
            $table->foreignId('webhook_user_id')->nullable()->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('yclients_marketplace_installations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('webhook_user_id');
        });
    }
};

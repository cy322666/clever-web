<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sqns_settings', function (Blueprint $table): void {
            $table->text('registered_webhook_url')->nullable()->after('webhook_key');
        });
    }

    public function down(): void
    {
        Schema::table('sqns_settings', function (Blueprint $table): void {
            $table->dropColumn('registered_webhook_url');
        });
    }
};

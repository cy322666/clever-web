<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('yclients_settings', function (Blueprint $table): void {
            $table->json('branch_settings')->nullable()->after('pipelines');
        });
    }

    public function down(): void
    {
        Schema::table('yclients_settings', function (Blueprint $table): void {
            $table->dropColumn('branch_settings');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('yclients_records', function (Blueprint $table): void {
            $table->index(['user_id', 'created_at', 'id'], 'yclients_records_user_created_id_idx');
            $table->index(['user_id', 'updated_at', 'id'], 'yclients_records_user_updated_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('yclients_records', function (Blueprint $table): void {
            $table->dropIndex('yclients_records_user_created_id_idx');
            $table->dropIndex('yclients_records_user_updated_id_idx');
        });
    }
};

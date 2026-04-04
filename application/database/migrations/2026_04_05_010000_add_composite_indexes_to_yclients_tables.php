<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('yclients_clients', function (Blueprint $table) {
            $table->index(
                ['account_id', 'setting_id', 'user_id', 'company_id', 'client_id'],
                'yclients_clients_lookup_idx'
            );
        });

        Schema::table('yclients_records', function (Blueprint $table) {
            $table->index(
                ['account_id', 'setting_id', 'user_id', 'company_id', 'client_id'],
                'yclients_records_client_scope_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('yclients_clients', function (Blueprint $table) {
            $table->dropIndex('yclients_clients_lookup_idx');
        });

        Schema::table('yclients_records', function (Blueprint $table) {
            $table->dropIndex('yclients_records_client_scope_idx');
        });
    }
};

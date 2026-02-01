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
        Schema::table('alfacrm_settings', function (Blueprint $table) {
            $table->string('status_archive', 20)->nullable();
            $table->string('status_pay', 20)->nullable();
            $table->string('status_repeated', 20)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alfacrm_settings', function (Blueprint $table) {
            $table->dropColumn(['status_archive', 'status_pay', 'status_repeated']);
        });
    }
};

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
        Schema::table('import_records', function (Blueprint $table) {
            $table->boolean('searched_contact')->nullable();
            $table->boolean('searched_company')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
//        Schema::table('alfacrm_settings', function (Blueprint $table) {
//            $table->dropColumn(['status_archive', 'status_pay', 'status_repeated']);
//        });
    }
};

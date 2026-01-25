<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
//        Schema::table('amocrm_staffs', function (Blueprint $table) {
//            $table->boolean('active')
//                ->default(true)
//                ->index();
//        });

        Schema::table('amocrm_fields', function (Blueprint $table) {
            $table->boolean('active')
                ->default(true)
                ->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('amocrm_staffs', function (Blueprint $table) {
            $table->dropColumn('active');
        });

        Schema::table('amocrm_fields', function (Blueprint $table) {
            $table->dropColumn('active');
        });
    }
};

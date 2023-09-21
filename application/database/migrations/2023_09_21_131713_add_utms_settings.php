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
        Schema::table('tilda_settings', function (Blueprint $table) {

            $table->string('utms')->default('merge');
        });

        Schema::table('bizon_settings', function (Blueprint $table) {

            $table->string('utms')->default('merge');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tilda_settings', function (Blueprint $table) {

            $table->dropColumn('utms');
        });

        Schema::table('bizon_settings', function (Blueprint $table) {

            $table->dropColumn('utms');
        });
    }
};

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
        Schema::table('distribution_transactions', function (Blueprint $table) {

            $table->integer('staff_id')->nullable();
            $table->string('staff_name')->nullable();
            $table->integer('staff_amocrm_id')->nullable();
            $table->boolean('schedule')->nullable();
            $table->integer('template')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('distribution_transactions', function (Blueprint $table) {

            $table->dropColumn('staff_id');
            $table->dropColumn('staff_name');
            $table->dropColumn('staff_amocrm_id');
            $table->boolean('schedule');
            $table->integer('template');
        });
    }
};

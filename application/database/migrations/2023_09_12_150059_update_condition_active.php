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
        Schema::table('active_lead_settings', function (Blueprint $table) {

//            $table->dropColumn('condition');

            $table->integer('condition')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('active_lead_settings', function (Blueprint $table) {

            $table->boolean('condition');
        });
    }
};

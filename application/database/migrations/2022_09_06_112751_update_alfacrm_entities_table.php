<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('alfacrm_lead_sources', function (Blueprint $table) {

            $table->integer('source_id')->nullable();
        });

        Schema::table('alfacrm_lead_statuses', function (Blueprint $table) {

            $table->integer('status_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('alfacrm_lead_statuses', function (Blueprint $table) {

            $table->dropColumn('status_id');
        });

        Schema::table('alfacrm_lead_sources', function (Blueprint $table) {

            $table->dropColumn('source_id');
        });
    }
};

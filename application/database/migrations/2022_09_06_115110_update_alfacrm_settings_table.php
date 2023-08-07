<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('alfacrm_settings', function (Blueprint $table) {

            $table->integer('stage_record_1')->nullable();
            $table->integer('stage_came_1')->nullable();
            $table->integer('stage_omission_1')->nullable();
        });
    }

    public function down()
    {
        Schema::table('alfacrm_settings', function (Blueprint $table) {

            $table->dropColumn('stage_record_1');
            $table->dropColumn('stage_came_1');
            $table->dropColumn('stage_omission_1');
        });
    }
};

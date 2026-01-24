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
        Schema::table('alfacrm_settings', function (Blueprint $table) {

            $table->string('status_came', 15)->nullable();
            $table->string('status_omission', 15)->nullable();
            $table->string('stage_record', 15)->nullable();
            $table->string('stage_came', 15)->nullable();
            $table->string('stage_omission', 15)->nullable();

            $table->dropColumn('status_came_1');
            $table->dropColumn('status_came_2');
            $table->dropColumn('status_came_3');

            $table->dropColumn('status_record_1');
            $table->dropColumn('status_record_2');
            $table->dropColumn('status_record_3');

            $table->dropColumn('status_omission_1');
            $table->dropColumn('status_omission_2');
            $table->dropColumn('status_omission_3');

            $table->dropColumn('stage_came_1');
            $table->dropColumn('stage_came_2');
            $table->dropColumn('stage_came_3');

            $table->dropColumn('stage_record_1');
            $table->dropColumn('stage_record_2');
            $table->dropColumn('stage_record_3');

            $table->dropColumn('stage_omission_1');
            $table->dropColumn('stage_omission_2');
            $table->dropColumn('stage_omission_3');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};

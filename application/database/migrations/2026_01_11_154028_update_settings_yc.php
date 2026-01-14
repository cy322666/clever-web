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
        Schema::table('yclients_settings', function (Blueprint $table) {

            $table->dropColumn('status_id_cancel');
            $table->dropColumn('status_id_wait');
            $table->dropColumn('status_id_came');
            $table->dropColumn('status_id_confirm');
            $table->dropColumn('status_id_delete');

            $table->dropColumn('token');
            $table->dropColumn('pipeline_id');


            $table->string('user_token')->nullable();
            $table->string('partner_token')->nullable();

            $table->string('status_id_cancel', 20)->nullable();
            $table->string('status_id_wait', 20)->nullable();
            $table->string('status_id_came', 20)->nullable();
            $table->string('status_id_confirm', 20)->nullable();
            $table->string('status_id_delete', 20)->nullable();
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

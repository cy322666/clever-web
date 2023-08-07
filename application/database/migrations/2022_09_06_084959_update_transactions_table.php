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
        Schema::table('alfacrm_transactions', function (Blueprint $table) {

            $table->dropColumn('user_id');
            $table->dropColumn('amo_contact_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('alfacrm_transactions', function (Blueprint $table) {

            $table->dropColumn('webhook_id');
            $table->integer('amo_contact_id')->nullable();
        });
    }
};

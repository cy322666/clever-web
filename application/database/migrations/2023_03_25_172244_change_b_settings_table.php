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
        Schema::table('bizon_settings', function (Blueprint $table) {

            $table->dropColumn('account_id');
            $table->dropColumn('response_user_name');

            $table->integer('user_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('bizon_settings', function (Blueprint $table) {

            $table->integer('account_id')->nullable();
            $table->string('response_user_name')->nullable();

            $table->dropColumn('user_id');
        });
    }
};

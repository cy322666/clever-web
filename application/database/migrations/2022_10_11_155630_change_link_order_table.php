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
        Schema::table('getcourse_orders', function (Blueprint $table) {

            $table->renameColumn('payment_link', 'link');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('getcourse_orders', function (Blueprint $table) {

            $table->renameColumn('link', 'payment_link');
        });
    }
};

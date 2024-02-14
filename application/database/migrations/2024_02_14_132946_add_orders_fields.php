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
        Schema::table('getcourse_orders', function (Blueprint $table) {

            $table->integer('template')->nullable();

            $table->dropColumn('response_user_id_order');
            $table->dropColumn('response_user_id_default');
            $table->dropColumn('status_id_order_close');
            $table->dropColumn('status_id_order');
            $table->dropColumn('tag_order');
            $table->dropColumn('utms');
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

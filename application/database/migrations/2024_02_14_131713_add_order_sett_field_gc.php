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
        Schema::table('getcourse_settings', function (Blueprint $table) {

            $table->json('order_settings')->nullable();

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
        Schema::table('getcourse_settings', function (Blueprint $table) {

            $table->dropColumn('order_settings');

            $table->string('response_user_id_order')->nullable();
            $table->string('response_user_id_default')->nullable();
            $table->string('status_id_order_close')->nullable();
            $table->string('status_id_order')->nullable();
            $table->string('tag_order')->nullable();
            $table->string('utms')->nullable();
        });
    }
};

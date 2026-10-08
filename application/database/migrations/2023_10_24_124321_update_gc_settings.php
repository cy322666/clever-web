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

            $table->string('utms')->default('merge');
            $table->json('settings')->nullable();

            $table->dropColumn('status_id_form');
            $table->dropColumn('response_user_id_form');
            $table->dropColumn('tag_form');
        });

        Schema::table('tilda_settings', function (Blueprint $table) {

            $table->dropColumn('name');
            $table->dropColumn('email');
            $table->dropColumn('phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tilda_settings', function (Blueprint $table) {

            $table->dropColumn('name');
            $table->dropColumn('email');
            $table->dropColumn('phone');
        });

        Schema::table('getcourse_settings', function (Blueprint $table) {

            $table->dropColumn('utms');
            $table->dropColumn('settings');

            $table->string('status_id_form')->nullable();
            $table->string('response_user_id_form')->nullable();
            $table->string('tag_form')->nullable();
        });
    }
};

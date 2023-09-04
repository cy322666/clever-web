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
        Schema::table('bizon_settings', function (Blueprint $table) {

            $table->integer('status_id_form')->nullable();
            $table->integer('pipeline_id_form')->nullable();
            $table->integer('responsible_user_id_form')->nullable();
            $table->integer('tag_form')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bizon_settings', function (Blueprint $table) {

            $table->dropColumn('status_id_form');
            $table->dropColumn('pipeline_id_form');
            $table->dropColumn('responsible_user_id_form');
            $table->dropColumn('tag_form');
        });
    }
};

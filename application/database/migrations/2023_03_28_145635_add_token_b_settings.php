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

            $table->string('token')->nullable();
            $table->string('login')->nullable();
            $table->string('password')->nullable();
        });

        Schema::table('apps', function (Blueprint $table) {

            $table->boolean('active')->default(false);
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

            $table->dropColumn('token');
            $table->dropColumn('login');
            $table->dropColumn('password');
        });

        Schema::table('apps', function (Blueprint $table) {

            $table->dropColumn('active');
        });
    }
};

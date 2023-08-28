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
        Schema::table('amocrm_staffs', function (Blueprint $table) {

            $table->integer('group_id')->nullable();
            $table->string('group_name')->default(true);
            $table->boolean('active')->nullable();
            $table->string('login')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('admin')->nullable();
        });

        Schema::table('amocrm_statuses', function (Blueprint $table) {

            $table->boolean('is_main')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('amocrm_staffs', function (Blueprint $table) {

            $table->dropColumn('group_id');
            $table->dropColumn('group_name');
            $table->dropColumn('active');
            $table->dropColumn('login');
            $table->dropColumn('phone');
            $table->dropColumn('admin');
        });

        Schema::table('amocrm_statuses', function (Blueprint $table) {

            $table->dropColumn('is_main');
        });
    }
};

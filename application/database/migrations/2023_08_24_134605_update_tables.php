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
        Schema::table('accounts', function (Blueprint $table) {

            $table->dropColumn('name');
        });

        Schema::table('amocrm_staffs', function (Blueprint $table) {

            $table->string('group')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {

            $table->string('name')->nullable();
        });

        Schema::table('amocrm_staffs', function (Blueprint $table) {

            $table->dropColumn('name');
        });
    }
};

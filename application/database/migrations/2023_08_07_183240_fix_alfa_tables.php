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
        Schema::table('alfacrm_lead_sources', function (Blueprint $table) {

            $table->dropColumn('account_id');

            $table->integer('user_id')->nullable();
        });

        Schema::table('alfacrm_lead_statuses', function (Blueprint $table) {

            $table->dropColumn('account_id');

            $table->integer('user_id')->nullable();
        });

        Schema::table('alfacrm_fields', function (Blueprint $table) {

            $table->integer('entity')->nullable();
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->integer('user_id')->nullable();
        });

        Schema::table('alfacrm_settings', function (Blueprint $table) {

            $table->string('domain')->nullable();
            $table->string('email')->nullable();
            $table->string('api_key')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alfacrm_lead_sources', function (Blueprint $table) {

            $table->dropColumn('user_id');

            $table->integer('account_id')->nullable();
        });

        Schema::table('alfacrm_lead_statuses', function (Blueprint $table) {

            $table->dropColumn('user_id');

            $table->integer('account_id')->nullable();
        });

        Schema::table('alfacrm_fields', function (Blueprint $table) {

            $table->dropColumn('entity');
            $table->dropColumn('name');
            $table->dropColumn('code');
            $table->dropColumn('user_id');
        });

        Schema::table('alfacrm_settings', function (Blueprint $table) {

            $table->dropColumn('domain');
            $table->dropColumn('email');
            $table->dropColumn('api_key');
        });
    }
};

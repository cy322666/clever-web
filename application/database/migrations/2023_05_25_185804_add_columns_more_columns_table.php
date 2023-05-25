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
        Schema::table('getcourse_settings', function (Blueprint $table) {
            $table->string('lead_name_order')->nullable();
            $table->string('lead_name_form')->nullable();
            $table->string('tag_order')->nullable();
            $table->string('tag_form')->nullable();
        });

        Schema::table('getcourse_orders', function (Blueprint $table) {
            $table->dropColumn('webhook_id');
            $table->dropColumn('error');
            $table->dropColumn('user_id');
        });

        Schema::table('getcourse_forms', function (Blueprint $table) {
            $table->integer('user_id')->nullable();
        });

        Schema::table('amocrm_staffs', function (Blueprint $table) {
            $table->dropColumn('account_id');
            $table->integer('user_id');
        });

        Schema::table('amocrm_pipelines', function (Blueprint $table) {
            $table->dropColumn('account_id');
            $table->integer('user_id');
        });

        Schema::table('amocrm_statuses', function (Blueprint $table) {
            $table->dropColumn('account_id');
            $table->integer('user_id');
        });

        Schema::drop('service_accesses');
        Schema::drop('amocrm_pipelines');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('getcourse_orders', function (Blueprint $table) {
            $table->string('webhook_id');
            $table->string('error');
            $table->string('user_id');
        });

        Schema::table('getcourse_forms', function (Blueprint $table) {
            $table->dropColumn('user_id');
        });

        Schema::table('amocrm_staffs', function (Blueprint $table) {
            $table->string('account_id');
            $table->dropColumn('user_id');
        });

        Schema::table('amocrm_statuses', function (Blueprint $table) {
            $table->string('account_id');
            $table->dropColumn('user_id');
        });

        Schema::table('amocrm_pipelines', function (Blueprint $table) {
            $table->string('account_id');
            $table->dropColumn('user_id');
        });

        Schema::create('service_accesses', function (Blueprint $table) {
            $table->string('user_id');
        });

        Schema::create('amocrm_pipelines', function (Blueprint $table) {
            $table->string('user_id');
        });

        Schema::table('getcourse_settings', function (Blueprint $table) {
            $table->dropColumn('lead_name_order');
            $table->dropColumn('lead_name_form');
            $table->dropColumn('tag_order');
            $table->dropColumn('tag_form');
        });
    }
};

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
        Schema::table('getcourse_forms', function (Blueprint $table) {

            $table->string('utm_medium')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_term')->nullable();
            $table->string('utm_campaign')->nullable();

            $table->dropColumn('webhook_id');
            $table->dropColumn('error');
            $table->dropColumn('user_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('getcourse_forms', function (Blueprint $table) {

            $table->dropColumn('utm_medium');
            $table->dropColumn('utm_content');
            $table->dropColumn('utm_source');
            $table->dropColumn('utm_term');
            $table->dropColumn('utm_campaign');

            $table->string('webhook_id')->nullable();
            $table->string('error')->nullable();
            $table->string('user_id')->nullable();
        });
    }
};

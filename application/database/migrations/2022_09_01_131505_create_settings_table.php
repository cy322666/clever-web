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
        Schema::create('alfacrm_settings', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

//            $table->integer('account_id')->nullable();

            $table->boolean('work_lead')->default(false);
            $table->boolean('active')->default(false);

            $table->integer('status_came_1')->nullable();
            $table->integer('status_came_2')->nullable();
            $table->integer('status_came_3')->nullable();

            $table->integer('status_record_1')->nullable();
            $table->integer('status_record_2')->nullable();
            $table->integer('status_record_3')->nullable();

            $table->integer('status_omission_1')->nullable();
            $table->integer('status_omission_2')->nullable();
            $table->integer('status_omission_3')->nullable();

            $table->integer('user_id')->nullable();

            $table->json('fields')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('alfacrm_settings');
    }
};

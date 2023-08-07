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
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->integer('user_id')->nullable();
//            $table->integer('account_id')->nullable();
            $table->string('app_name')->nullable();
            $table->integer('app_id')->nullable();
            $table->boolean('active')->nullable();
            $table->string('path')->nullable();
            $table->string('type')->nullable();
            $table->string('platform')->nullable();
            $table->uuid()->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('webhooks');
    }
};

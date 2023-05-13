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
        Schema::create('getcourse_settings', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->integer('user_id')->nullable();

            $table->string('status_id_form')->nullable();
            $table->string('status_id_order')->nullable();
            $table->string('status_id_order_close')->nullable();

            $table->integer('response_user_id_default')->nullable();
            $table->integer('response_user_id_form')->nullable();
            $table->integer('response_user_id_order')->nullable();

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
        Schema::dropIfExists('getcourse_settings');
    }
};

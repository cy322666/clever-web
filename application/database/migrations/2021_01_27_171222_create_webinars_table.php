<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWebinarsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('bizon_webinars', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string('event')->nullable();
            $table->string('roomid')->nullable();
            $table->string('webinarId')->nullable();
            $table->string('room_title')->nullable();
            $table->string('created')->nullable();
            $table->string('group')->nullable();
            $table->integer('stat')->nullable();
            $table->integer('len')->nullable();
            $table->integer('account_id')->nullable();
            $table->string('status')->default('wait');

            //TODO indexes
        });
    }

    //TODO indexes

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('webinars');
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateViewersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('bizon_viewers', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->integer('playVideo')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('username')->nullable();
            $table->string('ip')->nullable();
            $table->string('utm_term')->nullable();
            $table->boolean('mob')->nullable();
            $table->string('useragent')->nullable();
            $table->string('referer')->nullable();
            $table->string('cu1')->nullable();
            $table->string('p1')->nullable();
            $table->string('p2')->nullable();
            $table->string('p3')->nullable();
            $table->string('roomid')->nullable();
            $table->string('chatUserId')->nullable();
            $table->string('country')->nullable();
            $table->string('tz')->nullable();
            $table->string('region')->nullable();
            $table->string('created')->nullable();
            $table->string('view')->nullable();
            $table->string('url')->nullable();
            $table->string('cv')->nullable();
            $table->string('city')->nullable();
            $table->string('viewTill')->nullable();
            $table->string('webinarId')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->integer('messages_num')->nullable();
            $table->integer('webinar_id')->nullable();
            $table->text('commentaries')->nullable();
            $table->integer('contact_id')->nullable();
            $table->integer('lead_id')->nullable();
            $table->integer('time')->nullable();
            $table->string('status')->default('wait');

            //TODO indexes
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('viewers');
    }
}

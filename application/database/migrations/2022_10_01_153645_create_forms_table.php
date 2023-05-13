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
        Schema::create('getcourse_forms', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('name')->nullable();
            $table->integer('status')->default(0);

            $table->integer('lead_id')->nullable();
            $table->integer('contact_id')->nullable();

            $table->integer('webhook_id')->nullable();
            $table->integer('user_id')->nullable();

            $table->text('error')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('getcourse_forms');
    }
};

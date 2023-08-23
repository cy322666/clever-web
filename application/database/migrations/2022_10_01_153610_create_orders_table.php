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
        Schema::create('getcourse_orders', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->integer('number')->nullable();
            $table->integer('order_id')->nullable();
            $table->string('positions')->nullable();
            $table->string('left_cost_money')->nullable();
            $table->string('cost_money')->nullable();
            $table->string('payed_money')->nullable();
            $table->integer('status')->default(0);
            $table->string('payment_link')->nullable();
            $table->string('status_order')->nullable();

            $table->integer('lead_id')->nullable();
            $table->integer('contact_id')->nullable();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

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
        Schema::dropIfExists('getcourse_orders');
    }
};

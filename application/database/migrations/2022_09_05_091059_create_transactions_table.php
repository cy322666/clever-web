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
        Schema::create('alfacrm_transactions', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->json('fields')->nullable();

            $table->integer('user_id')->nullable();
            $table->integer('account_id')->nullable();

            $table->integer('amo_lead_id')->nullable();
            $table->integer('amo_contact_id')->nullable();

            $table->integer('alfa_branch_id')->nullable();
            $table->integer('alfa_client_id')->nullable();

            $table->string('comment')->nullable();

            $table->string('status')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('alfacrm_transactions');
    }
};

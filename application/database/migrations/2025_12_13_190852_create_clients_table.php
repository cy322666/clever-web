<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateClientsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('yclients_clients', function (Blueprint $table) {

            $table->increments('id');
            $table->integer('client_id');
            $table->integer('contact_id')->nullable();
            $table->string('name')->nullable();
            $table->integer('company_id')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->dateTime('birth_date')->nullable();
            $table->string('visits')->nullable();
            $table->string('spent')->nullable();
            $table->timestamps();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('setting_id')->constrained('yclients_settings')->cascadeOnDelete();

            $table->index('company_id');
            $table->index('client_id');
            $table->index('contact_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('clients');
    }
}

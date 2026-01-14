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
        Schema::create('yclients_settings', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->boolean('active')->default(false);

            $table->integer('pipeline_id')->nullable();

            $table->integer('status_id_cancel')->nullable();
            $table->integer('status_id_wait')->nullable();
            $table->integer('status_id_came')->nullable();
            $table->integer('status_id_confirm')->nullable();
            $table->integer('status_id_delete')->nullable();

            $table->string('token')->nullable();
            $table->string('login')->nullable();
            $table->string('password')->nullable();

            $table->json('branches')->nullable();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('yclients_settings');
    }
};

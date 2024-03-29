<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStatusesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('amocrm_statuses', function (Blueprint $table) {
            $table->id();
            $table->integer('pipeline_id')->nullable();
            $table->string('name')->nullable();
            $table->integer('status_id')->nullable();
            $table->string('color')->nullable();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //Schema::dropIfExists('pipeline_statuses');
    }
}

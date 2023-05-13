<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePipelinesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('amocrm_pipelines', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->integer('pipeline_id')->nullable();
            $table->integer('account_id')->nullable();
            
            $table->index('account_id');
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

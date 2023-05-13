<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStaffsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('amocrm_staffs', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->integer('staff_id')->nullable();
            $table->string('name')->nullable();
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
        Schema::dropIfExists('staffs');
    }
}

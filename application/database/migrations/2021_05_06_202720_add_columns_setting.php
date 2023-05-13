<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

class AddColumnsSetting extends Migration
{
    public function up()
    {
        Schema::table('bizon_settings', function(Blueprint $table) {
            $table->integer('time_cold')->nullable();
            $table->integer('time_soft')->nullable();
            $table->integer('time_hot')->nullable();
        });

//        Schema::table('user_settings', function(Blueprint $table) {
//            $table->boolean('is_private')->nullable();
//        });
    }
    
    public function down()
    {
    }
}


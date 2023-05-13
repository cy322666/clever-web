<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

class AddColumnAccount extends Migration
{
    public function up()
    {
        Schema::table('accounts', function(Blueprint $table) {
            $table->string('work')->nullable();
            $table->string('token_type')->nullable();
            $table->integer('expires_in')->nullable();
        });

//        Schema::table('user_settings', function(Blueprint $table) {
//            $table->boolean('is_private')->nullable();
//        });
    }
    
    public function down()
    {
    }
}


<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

class AddColumnsUser extends Migration
{
    public function up()
    {
        Schema::table('users', function(Blueprint $table) {
            $table->integer('account_id')->nullable();
        });

        Schema::table('accounts', function(Blueprint $table) {
            $table->integer('user_id')->nullable();
        });
    }
    
    public function down()
    {
    }
}


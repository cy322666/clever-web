<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

class AddColumnUuid extends Migration
{
    public function up()
    {
        Schema::table('users', function(Blueprint $table) {

            $table->string('uuid')->nullable();
        });
    }
    
    public function down()
    {
    }
}


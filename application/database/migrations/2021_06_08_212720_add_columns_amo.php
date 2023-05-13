<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

class AddColumnsAmo extends Migration
{
    public function up()
    {
        Schema::table('amocrm_pipelines', function(Blueprint $table) {

            $table->string('color')->nullable();
        });
        
        Schema::table('amocrm_staffs', function(Blueprint $table) {
        
            $table->string('group')->nullable();
        });
    }
    
    public function down()
    {
    }
}


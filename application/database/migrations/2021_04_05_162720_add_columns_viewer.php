<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

class AddColumnsViewer extends Migration
{
    public function up()
    {
        Schema::table('bizon_viewers', function(Blueprint $table) {
            $table->boolean('finished')->nullable();
            $table->string('clickBanner')->nullable();
            $table->string('clickFile')->nullable();
            $table->string('newOrder')->nullable();
            $table->string('orderDetails')->nullable();
        });

//        Schema::table('user_settings', function(Blueprint $table) {
//            $table->boolean('is_private')->nullable();
//        });
    }
    
    public function down()
    {
    }
}


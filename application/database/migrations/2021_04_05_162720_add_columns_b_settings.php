<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

class AddColumnsBSettings extends Migration
{
    public function up()
    {
        Schema::table('bizon_settings', function(Blueprint $table) {
            $table->integer('pipeline_id')->nullable();
            $table->integer('status_id_cold')->nullable();
            $table->integer('status_id_soft')->nullable();
            $table->integer('status_id_hot')->nullable();
            $table->string('tag_cold')->nullable();
            $table->string('tag_soft')->nullable();
            $table->string('tag_hot')->nullable();
            $table->string('tag')->nullable();
            $table->string('response_user_name')->nullable();
            $table->integer('response_user_id')->nullable();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        });

//        Schema::table('user_settings', function(Blueprint $table) {
//            $table->boolean('is_private')->nullable();
//        });
    }

    public function down()
    {
    }
}


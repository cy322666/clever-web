<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

class AddIndexes extends Migration
{
    public function up()
    {
        Schema::table('bizon_viewers', function(Blueprint $table) {

            $table->index('created_at');
            $table->index('webinarId');
            $table->index('webinar_id');
            $table->index('status');
        });

        Schema::table('bizon_webinars', function(Blueprint $table) {

            $table->index('account_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down()
    {
    }
}


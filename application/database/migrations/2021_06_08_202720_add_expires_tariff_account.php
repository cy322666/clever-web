<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

class AddExpiresTariffAccount extends Migration
{
    public function up()
    {
        Schema::table('accounts', function(Blueprint $table) {
            
            $table->timestamp('expires_tariff')->nullable();
        });
    }
    
    public function down()
    {
    }
}


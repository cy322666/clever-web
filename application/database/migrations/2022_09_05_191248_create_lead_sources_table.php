<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('alfacrm_lead_sources', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->integer('account_id')->nullable();
            $table->string('code')->nullable();
            $table->string('name')->nullable();
            $table->boolean('is_enabled')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('alfacrm_lead_sources');
    }
};

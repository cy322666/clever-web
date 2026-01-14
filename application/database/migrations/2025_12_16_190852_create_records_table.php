<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateRecordsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('yclients_records', function (Blueprint $table) {

            $table->increments('id');

            $table->integer('company_id')->nullable();
            $table->integer('record_id')->nullable();
            $table->integer('staff_id')->nullable();
            $table->string('staff_name')->nullable();
            $table->integer('client_id')->nullable();
            $table->integer('visit_id')->nullable();
            $table->integer('lead_id')->nullable();
            $table->dateTime('datetime')->nullable();
            $table->integer('cost')->nullable();
            $table->integer('attendance')->nullable();
            $table->string('title')->nullable();
            $table->string('comment')->nullable();
            $table->string('status')->nullable();
            $table->integer('seance_length')->nullable();
            $table->timestamps();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('setting_id')->constrained('yclients_settings')->cascadeOnDelete();

            $table->index('record_id');
            $table->index('client_id');
            $table->index('visit_id');
            $table->index('attendance');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('records');
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateServiceAccessesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('service_accesses', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->integer('account_id')->nullable();
            $table->integer('user_id')->nullable();

            $table->string('service_name')->nullable();

            $table->integer('status')->default(0);

            $table->string('subdomain')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->text('code')->nullable();
            $table->string('client_secret')->nullable();
            $table->string('redirect_uri')->nullable();
            $table->string('token_type')->nullable();
            $table->integer('expires_in')->nullable();
            $table->string('client_id')->nullable();
            $table->string('api_key')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('service_accesses');
    }
}

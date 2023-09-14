<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('data_leads', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->integer('status')->default(0);
            $table->integer('lead_id')->nullable();
            $table->integer('contact_id')->nullable();
            $table->string('source', 100)->nullable();
            $table->string('type', 50)->nullable();
            $table->string('phone_at', 50)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('country_code', 5)->nullable();
            $table->string('city_code', 5)->nullable();
            $table->string('number', 10)->nullable();
            $table->string('extension', 10)->nullable();
            $table->string('provider', 100)->nullable();
            $table->string('country', 50)->nullable();
            $table->string('region', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('timezone', 50)->nullable();
            $table->integer('qc_conflict')->nullable();
            $table->integer('qc')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_leads');
    }
};

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
        Schema::create('distribution_transactions', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->json('body')->nullable();
            $table->boolean('status')->default(false);
            $table->integer('lead_id')->nullable();
            $table->integer('contact_id')->nullable();
            $table->string('type')->nullable();//стратегия распределения //название

            $table->foreignId('distribution_setting_id')->constrained('distribution_settings')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('distribution_transactions');
    }
};

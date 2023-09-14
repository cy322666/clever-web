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
        Schema::create('data_settings', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->integer('field_country')->nullable();
            $table->integer('field_city')->nullable();
            $table->integer('field_timezone')->nullable();
            $table->integer('field_region')->nullable();
            $table->integer('field_provider')->nullable();

            $table->boolean('active')->default(false);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_settings');
    }
};

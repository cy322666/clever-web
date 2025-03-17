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
        Schema::create('analytic_settings', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->json('settings')->nullable();
            $table->boolean('active')->default(false);

            $table->string('driver')->nullable();
            $table->string('host')->nullable();
            $table->string('database')->nullable();
            $table->string('login')->nullable();
            $table->string('password')->nullable();
            $table->string('port')->nullable();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analytic_settings');
    }
};

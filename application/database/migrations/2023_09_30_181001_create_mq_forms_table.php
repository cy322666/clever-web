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
        Schema::create('marquiz_forms', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->integer('lead_id')->nullable();
            $table->integer('contact_id')->nullable();
            $table->json('body')->nullable();
            $table->integer('quiz')->nullable();
            $table->string('name')->nullable();
            $table->boolean('status')->default(false);

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marquiz_forms');
    }
};

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
        Schema::create('trigger_settings', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->string('trigger');
            $table->string('name');
            $table->integer('parent_trigger_id')->nullable();
            $table->json('conditions')->nullable();
            $table->json('actions');
            $table->boolean('active')->default(false);

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trigger_settings');
    }
};

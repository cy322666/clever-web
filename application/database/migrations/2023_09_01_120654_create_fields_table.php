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
        Schema::create('amocrm_fields', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->integer('field_id')->nullable();
            $table->string('name')->nullable();
            $table->string('type')->nullable();
            $table->string('code')->nullable();
            $table->integer('sort')->nullable();
            $table->boolean('is_api_only')->nullable();
            $table->string('entity_type')->nullable();
            $table->json('enums')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fields');
    }
};

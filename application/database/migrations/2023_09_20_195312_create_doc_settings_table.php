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
        Schema::create('doc_settings', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->json('settings')->nullable();
            $table->boolean('active')->default(false);
            $table->string('yandex_token')->nullable();
            $table->string('yandex_expires_in')->nullable();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doc_settings');
    }
};

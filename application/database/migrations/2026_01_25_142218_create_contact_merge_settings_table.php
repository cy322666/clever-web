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
        Schema::create('contact_merge_settings', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('active')->default(false);
            $table->json('match_fields')->nullable();
            $table->json('merge_rules')->nullable();
            $table->string('tag')->nullable();
            $table->boolean('auto_merge')->default(true);
            $table->string('master_strategy')->default('oldest');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_merge_settings');
    }
};

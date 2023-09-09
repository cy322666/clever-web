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
        Schema::create('active_lead_settings', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->string('tag_all')->nullable();
            $table->string('tag_pipeline')->nullable();
            $table->boolean('check_pipeline')->default(false);
            $table->boolean('active')->default(false);

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('active_leads_settings');
    }
};

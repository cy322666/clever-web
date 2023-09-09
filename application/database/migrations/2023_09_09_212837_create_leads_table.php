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
        Schema::create('active_leads', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->integer('pipeline_id')->nullable();
            $table->integer('contact_id')->nullable();
            $table->integer('lead_id')->nullable();
            $table->boolean('is_active')->default(false);
            $table->integer('status')->default(0);
            $table->integer('count_leads')->default(1);

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('active_leads');
    }
};

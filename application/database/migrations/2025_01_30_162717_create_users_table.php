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
        Schema::create('table_users', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->json('body')->nullable();
            $table->string('username')->nullable();
            $table->string('base_filename')->nullable();
            $table->boolean('status')->default(false);
            $table->integer('lead_id')->nullable();
            $table->integer('contact_id')->nullable();

//            $table->foreignId('table_setting_id')->constrained('distribution_settings')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->index(['table_setting_id', 'user_id']);
            $table->unique(['username', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('table_users');
    }
};

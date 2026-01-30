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
        Schema::create('company_cases', function (Blueprint $table) {
            $table->id();

            $table->string('slug')->unique();
            $table->string('title');
            $table->string('company_name')->nullable();
            $table->string('industry')->nullable();

            $table->text('excerpt')->nullable();
            $table->longText('description')->nullable();

            $table->string('logo_url')->nullable();
            $table->string('cover_url')->nullable();
            $table->json('tags')->nullable();

            $table->unsignedInteger('sort')->default(100);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_cases');
    }
};

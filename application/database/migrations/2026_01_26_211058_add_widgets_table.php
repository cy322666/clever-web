<?php

// database/migrations/2026_01_26_000001_create_widgets_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->id();

            $table->string('slug')->unique();          // auto-doubles
            $table->string('title');                   // Автопоиск дублей
            $table->text('excerpt')->nullable();       // коротко для карточки
            $table->longText('description')->nullable(); // полное описание

            $table->string('demo_vk_url')->nullable();
            $table->string('demo_youtube_url')->nullable();

            $table->string('logo_url')->nullable();    // картинка/иконка
            $table->json('tags')->nullable();          // ["Дубли","Контакты"]

            $table->enum('pricing_type', ['free', 'paid'])->default('paid');
            $table->unsignedInteger('price_from_rub')->nullable();
            $table->unsignedInteger('trial_days')->default(14);

            $table->unsignedInteger('installs_count')->default(0);
//            $table->unsignedInteger('stars_count')->default(0);

            $table->boolean('is_featured')->default(false);
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widgets');
    }
};


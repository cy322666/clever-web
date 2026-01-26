<?php
// database/migrations/2026_01_26_000002_create_widget_categories_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('widget_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique(); // automation
            $table->string('name');           // Автоматизация
            $table->unsignedInteger('sort')->default(100);
            $table->timestamps();
        });

        Schema::create('widget_category', function (Blueprint $table) {
            $table->foreignId('widget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('widget_category_id')->constrained()->cascadeOnDelete();
            $table->primary(['widget_id', 'widget_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_category');
        Schema::dropIfExists('widget_categories');
    }
};

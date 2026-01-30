<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('import_records', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('import_id')->constrained('import_settings')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('filename')->nullable();
            $table->integer('total_rows')->default(0);
            $table->integer('processed_rows')->default(0);
            $table->integer('success_rows')->default(0);
            $table->integer('error_rows')->default(0);

            $table->string('status')->default('processing'); // processing, completed, failed
            $table->text('error_message')->nullable();

            $table->json('row_data')->nullable(); // Данные строки для отображения
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_records');
    }
};

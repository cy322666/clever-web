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
        Schema::create('distribution_schedulers', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->json('settings')->nullable();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('amocrm_staffs')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('distribution_schedulers');
    }
};

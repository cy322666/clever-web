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
        Schema::create('contact_merge_records', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('setting_id')->constrained('contact_merge_settings')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('master_contact_id');
            $table->unsignedBigInteger('duplicate_contact_id');
            $table->json('match_fields')->nullable();
            $table->json('changes')->nullable();
            $table->string('status')->default('tagged');
            $table->text('message')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_merge_records');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('apps')
            ->where('name', 'tables')
            ->delete();

        Schema::dropIfExists('table_users');
        Schema::dropIfExists('table_settings');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('table_settings', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->json('settings')->nullable();
            $table->boolean('active')->default(false);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        });

        Schema::create('table_users', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->json('body')->nullable();
            $table->string('username')->nullable();
            $table->string('base_filename')->nullable();
            $table->boolean('status')->default(false);
            $table->integer('lead_id')->nullable();
            $table->integer('contact_id')->nullable();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unique(['username', 'user_id']);
        });
    }
};

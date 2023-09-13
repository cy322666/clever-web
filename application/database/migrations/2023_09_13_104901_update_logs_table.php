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
        Schema::table('logs', function (Blueprint $table) {

            $table->rename('amocrm_logs');

            $table->json('args')->nullable();
            $table->json('body')->nullable();
            $table->integer('retries')->nullable();
            $table->float('memory_usage')->nullable();
            $table->string('execution_time')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('amocrm_logs', function (Blueprint $table) {

            $table->rename('logs');

            $table->dropColumn('args');
            $table->dropColumn('body');
            $table->dropColumn('retries');
            $table->dropColumn('memory_usage');
            $table->dropColumn('execution_time');
        });
    }
};

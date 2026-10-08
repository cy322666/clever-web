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
        Schema::table('getcourse_forms', function (Blueprint $table) {

            $table->string('utm_medium', 50)->nullable();
            $table->string('utm_content', 50)->nullable();
            $table->string('utm_source', 50)->nullable();
            $table->string('utm_term', 50)->nullable();
            $table->string('utm_campaign', 50)->nullable();

            $table->dropColumn('user_id');
        });

        Schema::table('getcourse_forms', function (Blueprint $table) {

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('getcourse_forms', function (Blueprint $table) {

            $table->dropColumn('utm_medium', 50);
            $table->dropColumn('utm_content', 50);
            $table->dropColumn('utm_source', 50);
            $table->dropColumn('utm_term', 50);
            $table->dropColumn('utm_campaign', 50);
        });

        Schema::table('getcourse_forms', function (Blueprint $table) {

            $table->string('user_id');
        });
    }
};

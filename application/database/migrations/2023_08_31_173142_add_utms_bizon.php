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
        Schema::table('bizon_viewers', function (Blueprint $table) {

            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_content', 100)->nullable();
            $table->string('utm_source', 100)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bizon_viewers', function (Blueprint $table) {

            $table->dropColumn('utm_medium', 100);
            $table->dropColumn('utm_content', 100);
            $table->dropColumn('utm_source', 100);
        });
    }
};

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
        Schema::table('getcourse_settings', function (Blueprint $table) {

            $table->string('tag_order', 20)->nullable();
            $table->string('tag_form', 20)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('getcourse_settings', function (Blueprint $table) {

            $table->dropColumn('tag_order', 20);
            $table->dropColumn('tag_form', 20);
        });
    }
};

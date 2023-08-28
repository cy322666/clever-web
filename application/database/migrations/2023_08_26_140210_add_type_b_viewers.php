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

            $table->string('type')->nullable();
            $table->string('finished')->nullable();
            $table->string('clickFile')->nullable();
            $table->string('clickBanner')->nullable();
            $table->string('playVideo')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bizon_viewers', function (Blueprint $table) {

            $table->dropColumn('type');
            $table->dropColumn('finished');
            $table->dropColumn('clickFile');
            $table->dropColumn('clickBanner');
            $table->integer('playVideo')->change();
        });
    }
};

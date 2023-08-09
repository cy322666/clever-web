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
        Schema::table('users', function (Blueprint $table) {

            $table->boolean('is_root')->default(false);

            $table->dropColumn('account_id');

            $table->dateTime('expires_tariff_at')->nullable();
            $table->string('tariff')->default('trial');
        });

        Schema::table('accounts', function (Blueprint $table) {

            $table->dropColumn('endpoint');
            $table->dropColumn('status');
            $table->dropColumn('state');
            $table->dropColumn('token_bizon');
            $table->dropColumn('work');
            $table->dropColumn('token_type');
            $table->dropColumn('expires_tariff');
            $table->dropColumn('referer');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {

            $table->dropColumn('is_root');

            $table->integer('account_id')->nullable();

            $table->dropColumn('expires_tariff_at');
            $table->dropColumn('tariff');
        });

        Schema::table('accounts', function (Blueprint $table) {

            $table->string('endpoint')->nullable();
            $table->string('status')->nullable();
            $table->string('state')->nullable();
            $table->string('token_bizon')->nullable();
            $table->string('work')->nullable();
            $table->string('token_type')->nullable();
            $table->string('expires_tariff')->nullable();
            $table->string('referer')->nullable();
        });
    }
};

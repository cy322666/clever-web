<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->unsignedBigInteger('amo_account_id')->nullable()->after('widget');
            $table->unique(['amo_account_id', 'widget'], 'accounts_amo_account_widget_unique');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropUnique('accounts_amo_account_widget_unique');
            $table->dropColumn('amo_account_id');
        });
    }
};

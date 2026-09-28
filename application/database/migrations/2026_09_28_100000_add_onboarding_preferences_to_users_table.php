<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('locale', 5)->default('ru')->after('email');
            $table->string('crm_provider', 20)->nullable()->after('locale');
            $table->string('industry', 40)->nullable()->after('crm_provider');
            $table->timestamp('onboarding_completed_at')->nullable()->after('industry');
        });

        // The tour is mandatory only for accounts created after this migration.
        DB::table('users')->update(['onboarding_completed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['locale', 'crm_provider', 'industry', 'onboarding_completed_at']);
        });
    }
};

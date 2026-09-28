<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'crm_provider')) {
            return;
        }

        DB::table('users')
            ->whereNull('crm_provider')
            ->whereNotNull('onboarding_completed_at')
            ->update(['crm_provider' => 'amocrm']);
    }

    public function down(): void
    {
        // Existing preferences are intentionally preserved on rollback.
    }
};

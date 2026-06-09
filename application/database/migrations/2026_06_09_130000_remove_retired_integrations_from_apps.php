<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const RETIRED_INTEGRATIONS = [
        'active-lead',
        'data-info',
        'docs',
        'analytic',
        'contact-merge',
        'assistant',
        'amo-data',
    ];

    public function up(): void
    {
        DB::table('apps')
            ->whereIn('name', self::RETIRED_INTEGRATIONS)
            ->delete();
    }

    public function down(): void
    {
        // Removed app catalog rows cannot be reconstructed safely.
    }
};

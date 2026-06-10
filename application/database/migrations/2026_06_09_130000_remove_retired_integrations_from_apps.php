<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const RETIRED_INTEGRATIONS = [
        'active-lead',
        'data-info',
        'docs',
        'analytic',
        'contact-merge',
    ];

    public function up(): void
    {
        DB::table('apps')
            ->whereIn('name', self::RETIRED_INTEGRATIONS)
            ->delete();

        foreach (
            [
                'assistant_logs',
                'assistant_messages',
                'assistant_chat_sessions',
                'assistant_settings',
                'amo_data_sync_runs',
                'amo_data_tasks',
                'amo_data_leads',
                'amo_data_statuses',
                'amo_data_pipelines',
                'amo_data_staffs',
                'amo_data_fields',
                'amo_data_settings',
                'contact_merge_records',
                'contact_merge_settings',
                'docs_transactions',
                'doc_settings',
                'data_leads',
                'data_settings',
                'active_leads',
                'active_lead_settings',
                'analytic_settings',
            ] as $table
        ) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        // Removed app catalog rows cannot be reconstructed safely.
    }
};

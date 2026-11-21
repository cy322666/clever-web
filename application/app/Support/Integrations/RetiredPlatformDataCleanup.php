<?php

namespace App\Support\Integrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class RetiredPlatformDataCleanup
{
    public const MODULES = ['active-lead', 'amo-data', 'analytic', 'assistant', 'contact-merge', 'data-info', 'docs'];

    public const TABLES = ['ai_messages', 'ai_threads', 'amocrm_events', 'amocrm_tasks', 'amocrm_leads',
        'amocrm_sync_runs', 'assistant_logs', 'assistant_settings', 'contact_merge_records', 'contact_merge_settings',
        'active_leads', 'active_lead_settings', 'data_leads', 'data_settings', 'docs_transactions', 'doc_settings',
        'analytic_settings', 'amo_data_settings', 'feedback'];

    public function plan(): array
    {
        if (array_intersect(self::MODULES, array_keys(config('integrations.definitions', [])))) {
            throw new RuntimeException('A retired module is still registered in the integration catalog.');
        }
        $apps = Schema::hasTable('apps') ? DB::table('apps')->whereIn('name', self::MODULES)->get(['id', 'status', 'installed_at']) : collect();
        if ($apps->contains(fn ($app) => (int) $app->status === 1 || $app->installed_at !== null)) {
            throw new RuntimeException('A retired application is active or has an installation marker.');
        }
        $ids = $apps->pluck('id')->all();
        foreach (['widget_subscriptions', 'subscription_invoice_requests', 'subscription_plans'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            $references = DB::table($table)->whereIn('widget', self::MODULES);
            if ($ids !== [] && Schema::hasColumn($table, 'app_id')) {
                $references->orWhereIn('app_id', $ids);
            }
            if ($references->exists()) {
                throw new RuntimeException('Retired data is still referenced by billing: '.$table);
            }
        }
        foreach (Schema::getTables() as $table) {
            if (in_array($table['name'], self::TABLES, true)) {
                continue;
            }
            foreach (Schema::getForeignKeys($table['name']) as $key) {
                if (in_array($key['foreign_table'], self::TABLES, true)) {
                    throw new RuntimeException('Retired data is still referenced by '.$table['name']);
                }
            }
        }
        foreach (Schema::getViews() as $view) {
            foreach (self::TABLES as $table) {
                if (preg_match('/\b'.preg_quote($table, '/').'\b/i', $view['definition'] ?? '')) {
                    throw new RuntimeException('Retired data is still referenced by view '.$view['name']);
                }
            }
        }
        $tables = [];
        foreach (self::TABLES as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            if (Schema::hasColumn($table, 'active') && DB::table($table)->where('active', true)->exists()) {
                throw new RuntimeException('Retired settings are still active: '.$table);
            }
            foreach (['created_at', 'updated_at'] as $column) {
                if (Schema::hasColumn($table, $column) && DB::table($table)->where($column, '>=', now()->subDays(30))->exists()) {
                    throw new RuntimeException('Retired data has recent activity: '.$table);
                }
            }
            $tables[$table] = DB::table($table)->count();
        }

        return ['apps' => count($ids), 'app_ids' => $ids, 'tables' => $tables];
    }

    public function apply(): array
    {
        return DB::transaction(function (): array {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("SET LOCAL lock_timeout = '2s'");
                DB::statement("SET LOCAL statement_timeout = '60s'");
            }
            $plan = $this->plan();
            foreach (['webhooks', 'logs'] as $table) {
                if (!Schema::hasTable($table)) {
                    continue;
                }
                $query = DB::table($table)->whereIn('app_name', self::MODULES);
                if (Schema::hasColumn($table, 'app_id')) {
                    $query->orWhereIn('app_id', $plan['app_ids']);
                }
                $query->delete();
            }
            if ($plan['app_ids'] !== []) {
                DB::table('apps')->whereIn('id', $plan['app_ids'])->whereIn('name', self::MODULES)->delete();
            }
            foreach (self::TABLES as $table) {
                Schema::dropIfExists($table);
            }

            return $plan;
        });
    }
}

<?php

use App\Support\Integrations\RemovedCalculatorDataCleanup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const WIDGETS = ['alfacrm', 'calculator'];

    private const TABLES = [
        'calculator_transactions', 'calculator_settings', 'alfacrm_transactions',
        'alfacrm_fields', 'alfacrm_lead_sources', 'alfacrm_lead_statuses',
        'alfacrm_branches', 'alfacrm_customers', 'alfacrm_settings',
    ];

    public function up(): void
    {
        $accountIds = $this->ids('accounts', 'widget');
        $this->assertExclusiveAccounts($accountIds);

        $appIds = $this->ids('apps', 'name');
        $planIds = $this->ids('subscription_plans', 'widget');

        foreach (['widget_subscriptions', 'subscription_invoice_requests'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            DB::table($table)->where(function ($query) use ($table, $appIds, $planIds): void {
                $query->whereIn('widget', self::WIDGETS);
                if (Schema::hasColumn($table, 'app_id')) {
                    $query->orWhereIn('app_id', $appIds);
                }
                if (Schema::hasColumn($table, 'subscription_plan_id')) {
                    $query->orWhereIn('subscription_plan_id', $planIds);
                }
            })->delete();
        }

        foreach (['webhooks', 'logs'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            if (Schema::hasColumn($table, 'app_name')) {
                DB::table($table)->whereIn('app_name', self::WIDGETS)->delete();
            }
            if (Schema::hasColumn($table, 'app_id')) {
                DB::table($table)->whereIn('app_id', $appIds)->delete();
            }
        }

        if (Schema::hasTable('logs') && Schema::hasColumn('logs', 'url')) {
            DB::table('logs')->where('url', 'like', '%/api/alfacrm/%')->delete();
        }
        if (Schema::hasTable('api_requests')) {
            DB::table('api_requests')->where(function ($query): void {
                $query->where('path', 'like', 'api/alfacrm/%')
                    ->orWhere('path', 'like', '/api/alfacrm/%')
                    ->orWhere('route_name', 'like', 'alfacrm.%');
            })->delete();
        }

        foreach (['jobs', 'failed_jobs', 'queue_monitors'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->whereIn('queue', ['alfacrm_hook', 'alfacrm_record'])->delete();
            }
        }

        foreach (['jobs', 'failed_jobs'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            DB::table($table)->where('payload', 'like', '%AlfaCRM%')->select(['id', 'payload'])
                ->chunkById(200, function ($rows) use ($table): void {
                    foreach ($rows as $row) {
                        $payload = json_decode((string) $row->payload, true);
                        $class = (string) ($payload['data']['commandName'] ?? $payload['displayName'] ?? '');
                        if (str_starts_with($class, 'App\\Jobs\\AlfaCRM\\')) {
                            DB::table($table)->where('id', $row->id)->delete();
                        }
                    }
                });
        }

        if (Schema::hasTable('notifications')) {
            $ids = [];
            foreach (DB::table('notifications')->select(['id', 'data'])->cursor() as $row) {
                $data = json_decode((string) $row->data, true);
                $body = (string) ($data['body'] ?? '');
                if (preg_match('/(?:^|\R)Виджет: (?:alfacrm|calculator)\s*$/u', $body)) {
                    $ids[] = $row->id;
                }
            }
            foreach (array_chunk($ids, 200) as $chunk) {
                DB::table('notifications')->whereIn('id', $chunk)->delete();
            }
        }

        if (Schema::hasTable('widgets')) {
            $widgetIds = DB::table('widgets')->where(function ($query): void {
                $query->whereIn('slug', ['alfacrm', 'alfa-crm', 'calculator', 'calculator-fields'])
                    ->orWhereIn('title', ['AlfaCRM', 'Alfa CRM', 'АльфаСРМ', 'АльфаCRM', 'Альфа CRM', 'Калькулятор полей']);
            })->pluck('id')->all();
            if (Schema::hasTable('widget_category')) {
                DB::table('widget_category')->whereIn('widget_id', $widgetIds)->delete();
            }
            DB::table('widgets')->whereIn('id', $widgetIds)->delete();
        }

        app(RemovedCalculatorDataCleanup::class)->run();

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
        foreach (['apps' => $appIds, 'subscription_plans' => $planIds, 'accounts' => $accountIds] as $table => $ids) {
            if (Schema::hasTable($table)) {
                DB::table($table)->whereIn('id', $ids)->delete();
            }
        }
    }

    public function down(): void
    {
        // Removing these widgets and their data is intentionally irreversible.
    }

    private function ids(string $table, string $column): array
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, $column)
            ? DB::table($table)->whereIn($column, self::WIDGETS)->pluck('id')->all()
            : [];
    }

    private function assertExclusiveAccounts(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        // User 1 can share an active connection without an explicit foreign key.
        if (DB::table('accounts')->whereIn('id', $ids)->where('user_id', 1)->where('active', true)->exists()) {
            throw new RuntimeException('A retired widget account is still used by the shared amoCRM connection.');
        }
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            if (in_array($name, self::TABLES, true) || !Schema::hasColumn($name, 'account_id')) {
                continue;
            }
            if (DB::table($name)->whereIn('account_id', $ids)->exists()) {
                throw new RuntimeException("A retired widget account is still referenced by {$name}.");
            }
        }
    }
};

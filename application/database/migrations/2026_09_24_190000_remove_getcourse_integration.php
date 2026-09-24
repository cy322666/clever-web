<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const WIDGET = 'getcourse';

    private const QUEUES = ['getcourse_form', 'getcourse_order'];

    private const TABLES = ['getcourse_orders', 'getcourse_forms', 'getcourse_settings'];

    public function up(): void
    {
        $appIds = $this->ids('apps', 'name');
        $accountIds = $this->ids('accounts', 'widget');
        $planIds = $this->ids('subscription_plans', 'widget');

        $this->assertAccountsAreExclusive($accountIds);

        foreach (['widget_subscriptions', 'subscription_invoice_requests'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::table($table)->where(function ($query) use ($table, $appIds, $planIds): void {
                if (Schema::hasColumn($table, 'widget')) {
                    $query->orWhere('widget', self::WIDGET);
                }
                if (Schema::hasColumn($table, 'app_id')) {
                    $query->orWhereIn('app_id', $appIds);
                }
                if (Schema::hasColumn($table, 'subscription_plan_id')) {
                    $query->orWhereIn('subscription_plan_id', $planIds);
                }
            })->delete();
        }

        foreach (['webhooks', 'logs'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (Schema::hasColumn($table, 'app_name')) {
                DB::table($table)->where('app_name', self::WIDGET)->delete();
            }
            if (Schema::hasColumn($table, 'app_id')) {
                DB::table($table)->whereIn('app_id', $appIds)->delete();
            }
        }

        if (Schema::hasTable('api_requests')) {
            DB::table('api_requests')->where(function ($query): void {
                $query->where('path', 'like', '%/getcourse/%')
                    ->orWhere('route_name', 'like', 'getcourse.%');
            })->delete();
        }

        foreach (['jobs', 'failed_jobs', 'queue_monitors'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'queue')) {
                DB::table($table)->whereIn('queue', self::QUEUES)->delete();
            }
        }

        foreach (['jobs', 'failed_jobs'] as $table) {
            $this->deleteSerializedJobs($table);
        }

        if (Schema::hasTable('notifications')) {
            $ids = [];
            foreach (DB::table('notifications')->select(['id', 'data'])->cursor() as $row) {
                $data = json_decode((string) $row->data, true);
                $body = (string) ($data['body'] ?? '');
                if (preg_match('/(?:^|\R)Виджет: (?:getcourse|GetCourse|Геткурс)\s*$/u', $body)) {
                    $ids[] = $row->id;
                }
            }
            foreach (array_chunk($ids, 200) as $chunk) {
                DB::table('notifications')->whereIn('id', $chunk)->delete();
            }
        }

        if (Schema::hasTable('widgets')) {
            $widgetIds = DB::table('widgets')->where(function ($query): void {
                $query->whereIn('slug', ['getcourse', 'get-course'])
                    ->orWhereIn('title', ['GetCourse', 'Геткурс']);
            })->pluck('id')->all();

            if (Schema::hasTable('widget_category')) {
                DB::table('widget_category')->whereIn('widget_id', $widgetIds)->delete();
            }
            DB::table('widgets')->whereIn('id', $widgetIds)->delete();
        }

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
        // Removing the integration and its data is intentionally irreversible.
    }

    private function ids(string $table, string $column): array
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, $column)
            ? DB::table($table)->where($column, self::WIDGET)->pluck('id')->all()
            : [];
    }

    private function assertAccountsAreExclusive(array $accountIds): void
    {
        if ($accountIds === []) {
            return;
        }

        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            if (in_array($name, self::TABLES, true) || ! Schema::hasColumn($name, 'account_id')) {
                continue;
            }
            if (DB::table($name)->whereIn('account_id', $accountIds)->exists()) {
                throw new RuntimeException("A GetCourse account is still referenced by {$name}.");
            }
        }
    }

    private function deleteSerializedJobs(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'payload')) {
            return;
        }

        DB::table($table)->where('payload', 'like', '%GetCourse%')->select(['id', 'payload'])
            ->chunkById(200, function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    $payload = json_decode((string) $row->payload, true);
                    $class = (string) ($payload['data']['commandName'] ?? $payload['displayName'] ?? '');
                    if (str_starts_with($class, 'App\\Jobs\\GetCourse\\')) {
                        DB::table($table)->where('id', $row->id)->delete();
                    }
                }
            });
    }
};

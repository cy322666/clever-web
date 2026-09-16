<?php

namespace Tests\Feature\Integrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class PurgeIntegrationsTest extends TestCase
{
    private const DEDICATED = [
        'calculator_settings', 'calculator_transactions', 'alfacrm_settings', 'alfacrm_transactions',
        'alfacrm_fields', 'alfacrm_lead_sources', 'alfacrm_lead_statuses', 'alfacrm_branches', 'alfacrm_customers',
    ];

    private const SHARED = [
        'accounts', 'apps', 'subscription_plans', 'widget_subscriptions', 'subscription_invoice_requests',
        'webhooks', 'logs', 'api_requests', 'jobs', 'failed_jobs', 'queue_monitors', 'notifications',
        'widgets', 'widget_categories', 'widget_category', 'distribution_settings',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'purge_integrations_test',
            'database.connections.purge_integrations_test' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('purge_integrations_test');
        Http::preventStrayRequests();

        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('widget');
            $table->boolean('active');
            $table->softDeletes();
        });
        foreach (self::DEDICATED as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->id();
                $table->foreignId('account_id')->nullable()->constrained('accounts');
                $table->text('payload')->nullable();
                if ($name === 'calculator_transactions') {
                    $table->foreignId('calculator_setting_id')->nullable()->constrained('calculator_settings');
                }
            });
        }
        Schema::create('apps', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('user_id')->default(2);
            $table->unsignedInteger('status')->default(2);
            $table->softDeletes();
        });
        Schema::create('subscription_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('widget');
            $table->unsignedInteger('price_rub')->default(1990);
            $table->softDeletes();
        });
        foreach (['widget_subscriptions', 'subscription_invoice_requests'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->string('widget')->nullable();
                $table->foreignId('app_id')->nullable()->constrained('apps');
                $table->foreignId('subscription_plan_id')->nullable()->constrained('subscription_plans');
                $table->string('status')->default('active');
                $table->softDeletes();
            });
        }
        foreach (['webhooks', 'logs'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->string('app_name')->nullable();
                $table->foreignId('app_id')->nullable()->constrained('apps');
                $table->text('url')->nullable();
                $table->text('payload')->nullable();
            });
        }
        Schema::create('api_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('path');
            $table->string('route_name')->nullable();
        });
        foreach (['jobs', 'failed_jobs', 'queue_monitors'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->string('queue');
                $table->text('payload');
            });
        }
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->text('data');
        });
        Schema::create('widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('slug');
            $table->string('title');
            $table->softDeletes();
        });
        Schema::create('widget_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('slug');
        });
        Schema::create('widget_category', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('widget_id')->constrained('widgets');
            $table->foreignId('widget_category_id')->constrained('widget_categories');
        });
        Schema::create('distribution_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts');
            $table->text('payload');
        });
    }

    public function test_purge_removes_only_retired_data_including_deleted_and_linked_records(): void
    {
        $this->seedData();
        $expected = $this->snapshot(self::SHARED, true);

        $this->migration()->up();

        $this->assertSame($expected, $this->snapshot(self::SHARED));
        foreach (self::DEDICATED as $table) {
            $this->assertFalse(Schema::hasTable($table), $table . ' must be dropped');
        }
    }

    public function test_repeated_purge_and_down_do_not_recreate_any_data(): void
    {
        $this->seedData();
        $migration = $this->migration();
        $migration->up();
        $purged = $this->snapshot([...self::SHARED, ...self::DEDICATED]);

        $migration->up();
        $this->assertSame($purged, $this->snapshot([...self::SHARED, ...self::DEDICATED]));

        $migration->down();
        $this->assertSame($purged, $this->snapshot([...self::SHARED, ...self::DEDICATED]));
    }

    public function test_shared_account_reference_aborts_before_any_deletion(): void
    {
        $this->seedData();
        DB::table('distribution_settings')->insert(['id' => 1, 'account_id' => 2, 'payload' => 'Shared connection']);

        $this->assertGuardPreservesAllData('still referenced by distribution_settings');
    }

    public function test_active_user_one_account_aborts_before_any_deletion(): void
    {
        $this->seedData();
        DB::table('accounts')->where('id', 2)->update(['user_id' => 1, 'active' => true]);

        $this->assertGuardPreservesAllData('still used by the shared amoCRM connection');
    }

    private function assertGuardPreservesAllData(string $message): void
    {
        $tables = [...self::SHARED, ...self::DEDICATED];
        $before = $this->snapshot($tables);
        try {
            $this->migration()->up();
            $this->fail('Account guard must stop the purge.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshot($tables));
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_09_12_120000_remove_alfacrm_and_calculator_integrations.php');
    }

    private function snapshot(array $tables, bool $neighborsOnly = false): array
    {
        $snapshot = [];
        foreach ($tables as $table) {
            if (!Schema::hasTable($table)) {
                $snapshot[$table] = null;
                continue;
            }
            $query = DB::table($table)->orderBy('id');
            if ($neighborsOnly) {
                $query->where('id', '>=', 100);
            }
            $snapshot[$table] = $query->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }

    private function seedData(): void
    {
        DB::table('accounts')->insert([
            ['id' => 1, 'user_id' => 2, 'widget' => 'alfacrm', 'active' => false, 'deleted_at' => null],
            ['id' => 2, 'user_id' => 3, 'widget' => 'calculator', 'active' => false, 'deleted_at' => null],
            ['id' => 3, 'user_id' => 4, 'widget' => 'calculator', 'active' => false, 'deleted_at' => '2026-08-01'],
            ['id' => 100, 'user_id' => 1, 'widget' => 'distribution', 'active' => true, 'deleted_at' => null],
        ]);
        foreach (self::DEDICATED as $table) {
            DB::table($table)->insert(['id' => 1, 'account_id' => 2, 'payload' => 'Retired history']);
        }
        DB::table('calculator_transactions')->where('id', 1)->update(['calculator_setting_id' => 1]);
        DB::table('distribution_settings')->insert(['id' => 100, 'account_id' => 100, 'payload' => 'Keep this configuration']);
        foreach (['apps' => 'name', 'subscription_plans' => 'widget'] as $table => $column) {
            DB::table($table)->insert([
                ['id' => 1, $column => 'alfacrm', 'deleted_at' => null],
                ['id' => 2, $column => 'calculator', 'deleted_at' => '2026-08-01'],
                ['id' => 100, $column => 'distribution', 'deleted_at' => null],
                ['id' => 101, $column => 'alfacrm-migration', 'deleted_at' => '2026-08-01'],
            ]);
        }
        foreach (['widget_subscriptions', 'subscription_invoice_requests'] as $table) {
            DB::table($table)->insert([
                ['id' => 1, 'widget' => 'alfacrm', 'app_id' => null, 'subscription_plan_id' => null, 'deleted_at' => null],
                ['id' => 2, 'widget' => 'calculator', 'app_id' => null, 'subscription_plan_id' => null, 'deleted_at' => '2026-08-01'],
                ['id' => 3, 'widget' => null, 'app_id' => 1, 'subscription_plan_id' => null, 'deleted_at' => '2026-08-01'],
                ['id' => 4, 'widget' => 'distribution', 'app_id' => null, 'subscription_plan_id' => 2, 'deleted_at' => null],
                ['id' => 100, 'widget' => 'distribution', 'app_id' => 100, 'subscription_plan_id' => 100, 'deleted_at' => null],
                ['id' => 101, 'widget' => 'distribution', 'app_id' => 100, 'subscription_plan_id' => 100, 'deleted_at' => '2026-08-01'],
            ]);
        }
        foreach (['webhooks', 'logs'] as $table) {
            DB::table($table)->insert([
                ['id' => 1, 'app_name' => 'alfacrm', 'app_id' => null],
                ['id' => 2, 'app_name' => 'calculator', 'app_id' => null],
                ['id' => 3, 'app_name' => 'legacy-name', 'app_id' => 1],
                ['id' => 100, 'app_name' => 'distribution', 'app_id' => 100],
            ]);
            DB::table($table)->where('id', 100)->update(['payload' => 'Customer mentioned AlfaCRM and calculator']);
        }
        DB::table('logs')->insert(['id' => 4, 'url' => 'https://example.test/api/alfacrm/hook/123']);
        DB::table('logs')->insert(['id' => 101, 'url' => 'https://example.test/api/alfacrm-migration/123']);
        DB::table('api_requests')->insert([
            ['id' => 1, 'path' => 'api/alfacrm/hook/1', 'route_name' => null],
            ['id' => 2, 'path' => '/api/alfacrm/record/2', 'route_name' => null],
            ['id' => 3, 'path' => 'legacy', 'route_name' => 'alfacrm.hook'],
            ['id' => 100, 'path' => 'api/distribution/hook', 'route_name' => 'distribution.hook'],
            ['id' => 101, 'path' => 'api/alfacrm-migration/hook', 'route_name' => 'alfacrm-migration.hook'],
        ]);
        foreach (['jobs', 'failed_jobs', 'queue_monitors'] as $table) {
            DB::table($table)->insert([
                ['id' => 1, 'queue' => 'alfacrm_hook', 'payload' => '{}'],
                ['id' => 2, 'queue' => 'alfacrm_record', 'payload' => '{}'],
                ['id' => 100, 'queue' => 'default', 'payload' => json_encode(['displayName' => 'App\\Jobs\\Other', 'note' => 'AlfaCRM'])],
                ['id' => 101, 'queue' => 'alfacrm_migration', 'payload' => '{}'],
            ]);
        }
        foreach (['jobs', 'failed_jobs'] as $table) {
            DB::table($table)->insert([
                ['id' => 3, 'queue' => 'default', 'payload' => json_encode(['data' => ['commandName' => 'App\\Jobs\\AlfaCRM\\Hook']])],
                ['id' => 4, 'queue' => 'default', 'payload' => json_encode(['displayName' => 'App\\Jobs\\AlfaCRM\\Record'])],
                ['id' => 102, 'queue' => 'default', 'payload' => json_encode(['displayName' => 'App\\Jobs\\AlfaCRMMigration\\Run'])],
            ]);
        }
        DB::table('notifications')->insert([
            ['id' => 1, 'data' => json_encode(['body' => "Подписка завершена\nВиджет: alfacrm"])],
            ['id' => 2, 'data' => json_encode(['body' => 'Виджет: calculator'])],
            ['id' => 100, 'data' => json_encode(['body' => 'User asked about calculator and AlfaCRM'])],
            ['id' => 101, 'data' => json_encode(['body' => 'Виджет: distribution'])],
        ]);
        DB::table('widgets')->insert([
            ['id' => 1, 'slug' => 'alfa-crm', 'title' => 'Integration', 'deleted_at' => null],
            ['id' => 2, 'slug' => 'calculator-fields', 'title' => 'Integration', 'deleted_at' => '2026-08-01'],
            ['id' => 3, 'slug' => 'legacy-alpha', 'title' => 'Альфа CRM', 'deleted_at' => null],
            ['id' => 4, 'slug' => 'legacy-calculator', 'title' => 'Калькулятор полей', 'deleted_at' => null],
            ['id' => 100, 'slug' => 'distribution', 'title' => 'Распределение', 'deleted_at' => null],
            ['id' => 101, 'slug' => 'alfacrm-migration', 'title' => 'Перенос из AlfaCRM', 'deleted_at' => '2026-08-01'],
        ]);
        DB::table('widget_categories')->insert(['id' => 100, 'slug' => 'automation']);
        foreach ([1, 2, 3, 4, 100, 101] as $id) {
            DB::table('widget_category')->insert(['id' => $id, 'widget_id' => $id, 'widget_category_id' => 100]);
        }
    }
}

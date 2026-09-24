<?php

namespace Tests\Feature\Integrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class GetCourseRemovalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'getcourse_removal_test',
            'database.connections.getcourse_removal_test' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('getcourse_removal_test');

        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('widget');
        });
        Schema::create('apps', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('subscription_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('widget');
        });
        foreach (['widget_subscriptions', 'subscription_invoice_requests'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
                $table->string('widget')->nullable();
                $table->unsignedBigInteger('app_id')->nullable();
                $table->unsignedBigInteger('subscription_plan_id')->nullable();
            });
        }
        foreach (['webhooks', 'logs'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
                $table->string('app_name')->nullable();
                $table->unsignedBigInteger('app_id')->nullable();
            });
        }
        Schema::create('api_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('path');
            $table->string('route_name')->nullable();
        });
        foreach (['jobs', 'failed_jobs'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
                $table->string('queue');
                $table->text('payload');
            });
        }
        Schema::create('queue_monitors', function (Blueprint $table): void {
            $table->id();
            $table->string('queue');
        });
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->text('data');
        });
        Schema::create('widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('slug');
            $table->string('title');
        });
        Schema::create('widget_category', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('widget_id');
        });
        foreach (['getcourse_orders', 'getcourse_forms', 'getcourse_settings'] as $tableName) {
            Schema::create($tableName, fn (Blueprint $table) => $table->id());
        }
        Schema::create('external_account_references', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
        });
    }

    public function test_it_removes_only_getcourse_data_and_is_idempotent(): void
    {
        $this->seedData();

        $migration = $this->migration();
        $migration->up();
        $migration->up();
        $migration->down();

        foreach (['getcourse_orders', 'getcourse_forms', 'getcourse_settings'] as $tableName) {
            $this->assertFalse(Schema::hasTable($tableName));
        }
        foreach (['accounts', 'apps', 'subscription_plans'] as $tableName) {
            $this->assertSame([100], DB::table($tableName)->pluck('id')->all(), $tableName);
        }
        foreach (['widget_subscriptions', 'subscription_invoice_requests', 'webhooks', 'logs', 'api_requests', 'jobs', 'failed_jobs', 'queue_monitors', 'notifications', 'widgets'] as $tableName) {
            $this->assertSame([100], DB::table($tableName)->pluck('id')->all(), $tableName);
        }
        $this->assertSame([100], DB::table('widget_category')->pluck('widget_id')->all());
    }

    public function test_external_account_reference_aborts_before_deletion(): void
    {
        $this->seedData();
        DB::table('external_account_references')->insert(['id' => 1, 'account_id' => 1]);

        try {
            $this->migration()->up();
            $this->fail('Referenced GetCourse account must not be deleted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('external_account_references', $exception->getMessage());
        }

        $this->assertSame(2, DB::table('accounts')->count());
        $this->assertTrue(Schema::hasTable('getcourse_settings'));
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_09_24_190000_remove_getcourse_integration.php');
    }

    private function seedData(): void
    {
        DB::table('accounts')->insert([
            ['id' => 1, 'widget' => 'getcourse'],
            ['id' => 100, 'widget' => 'workflows'],
        ]);
        DB::table('apps')->insert([
            ['id' => 1, 'name' => 'getcourse'],
            ['id' => 100, 'name' => 'workflows'],
        ]);
        DB::table('subscription_plans')->insert([
            ['id' => 1, 'widget' => 'getcourse'],
            ['id' => 100, 'widget' => 'workflows'],
        ]);
        foreach (['widget_subscriptions', 'subscription_invoice_requests'] as $tableName) {
            DB::table($tableName)->insert([
                ['id' => 1, 'widget' => 'getcourse', 'app_id' => 1, 'subscription_plan_id' => 1],
                ['id' => 100, 'widget' => 'workflows', 'app_id' => 100, 'subscription_plan_id' => 100],
            ]);
        }
        foreach (['webhooks', 'logs'] as $tableName) {
            DB::table($tableName)->insert([
                ['id' => 1, 'app_name' => 'getcourse', 'app_id' => 1],
                ['id' => 100, 'app_name' => 'workflows', 'app_id' => 100],
            ]);
        }
        DB::table('api_requests')->insert([
            ['id' => 1, 'path' => '/api/getcourse/forms/1', 'route_name' => 'getcourse.form'],
            ['id' => 100, 'path' => '/api/workflows/webhook/1', 'route_name' => 'workflows.webhook'],
        ]);
        foreach (['jobs', 'failed_jobs'] as $tableName) {
            DB::table($tableName)->insert([
                ['id' => 1, 'queue' => 'default', 'payload' => json_encode(['displayName' => 'App\\Jobs\\GetCourse\\FormSend'])],
                ['id' => 2, 'queue' => 'getcourse_order', 'payload' => '{}'],
                ['id' => 100, 'queue' => 'default', 'payload' => json_encode(['displayName' => 'App\\Jobs\\Other'])],
            ]);
        }
        DB::table('queue_monitors')->insert([
            ['id' => 1, 'queue' => 'getcourse_form'],
            ['id' => 100, 'queue' => 'workflows'],
        ]);
        DB::table('notifications')->insert([
            ['id' => 1, 'data' => json_encode(['body' => "Подписка завершена\nВиджет: Геткурс"])],
            ['id' => 100, 'data' => json_encode(['body' => 'Виджет: Потоки'])],
        ]);
        DB::table('widgets')->insert([
            ['id' => 1, 'slug' => 'getcourse', 'title' => 'GetCourse'],
            ['id' => 100, 'slug' => 'workflows', 'title' => 'Потоки'],
        ]);
        DB::table('widget_category')->insert([
            ['id' => 1, 'widget_id' => 1],
            ['id' => 100, 'widget_id' => 100],
        ]);
        foreach (['getcourse_orders', 'getcourse_forms', 'getcourse_settings'] as $tableName) {
            DB::table($tableName)->insert(['id' => 1]);
        }
    }
}

<?php

namespace Tests\Feature\Integrations;

use App\Support\Integrations\RetiredPlatformDataCleanup;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class RetiredPlatformDataCleanupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true, 'integrations.definitions' => ['tilda' => []]]);
        DB::purge('sqlite');
        Http::preventStrayRequests();
        Schema::create('apps', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->integer('status'); $table->timestamp('installed_at')->nullable();
        });
        foreach (RetiredPlatformDataCleanup::MODULES as $name) {
            DB::table('apps')->insert(['name' => $name, 'status' => 0]);
        }
        DB::table('apps')->insert(['name' => 'tilda', 'status' => 1, 'installed_at' => now()]);
        foreach (RetiredPlatformDataCleanup::TABLES as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id(); $table->boolean('active')->default(false); $table->timestamps();
            });
            DB::table($name)->insert(['created_at' => now()->subDays(90), 'updated_at' => now()->subDays(90)]);
        }
    }

    public function test_cleanup_removes_only_the_exact_retired_cluster_and_is_repeatable(): void
    {
        $cleanup = app(RetiredPlatformDataCleanup::class);
        $plan = $cleanup->plan();
        $this->assertSame(7, $plan['apps']);
        $this->assertCount(19, $plan['tables']);
        $this->assertSame(8, DB::table('apps')->count());
        $cleanup->apply();
        $this->assertSame(['tilda'], DB::table('apps')->pluck('name')->all());
        foreach (RetiredPlatformDataCleanup::TABLES as $name) {
            $this->assertFalse(Schema::hasTable($name));
        }
        $this->assertSame(0, $cleanup->apply()['apps']);
    }

    public function test_a_live_foreign_key_aborts_before_any_data_is_deleted(): void
    {
        Schema::create('current_settings', function (Blueprint $table): void {
            $table->id(); $table->foreignId('assistant_setting_id')->constrained('assistant_settings');
        });
        $this->assertGuard('still referenced by current_settings');
    }

    public function test_shared_logs_without_module_columns_are_kept_and_only_retired_webhooks_are_deleted(): void
    {
        Schema::create('logs', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('user_id'); $table->text('data');
        });
        Schema::create('webhooks', function (Blueprint $table): void {
            $table->id(); $table->string('app_name'); $table->foreignId('app_id')->constrained('apps');
        });
        $retired = DB::table('apps')->where('name', 'assistant')->value('id');
        $current = DB::table('apps')->where('name', 'tilda')->value('id');
        DB::table('logs')->insert(['user_id' => 1, 'data' => 'Shared API diagnostics']);
        DB::table('webhooks')->insert([
            ['app_name' => 'assistant', 'app_id' => $retired],
            ['app_name' => 'tilda', 'app_id' => $current],
        ]);
        app(RetiredPlatformDataCleanup::class)->apply();
        $this->assertSame(['tilda'], DB::table('webhooks')->pluck('app_name')->all());
        $this->assertSame(['Shared API diagnostics'], DB::table('logs')->pluck('data')->all());
        app(RetiredPlatformDataCleanup::class)->apply();
        $this->assertSame(1, DB::table('logs')->count());
    }

    public function test_active_or_recent_retired_data_blocks_cleanup(): void
    {
        DB::table('assistant_settings')->update(['active' => true]);
        $this->assertGuard('still active');
        DB::table('assistant_settings')->update(['active' => false, 'updated_at' => now()]);
        $this->assertGuard('recent activity');
    }

    public function test_billing_reference_blocks_cleanup(): void
    {
        Schema::create('widget_subscriptions', function (Blueprint $table): void {
            $table->id(); $table->string('widget'); $table->integer('app_id')->nullable();
        });
        DB::table('widget_subscriptions')->insert(['widget' => 'assistant']);
        $this->assertGuard('referenced by billing');
    }

    private function assertGuard(string $message): void
    {
        try {
            app(RetiredPlatformDataCleanup::class)->apply();
            $this->fail('Cleanup guard must reject this database.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
        $this->assertSame(8, DB::table('apps')->count());
        foreach (RetiredPlatformDataCleanup::TABLES as $name) {
            $this->assertTrue(Schema::hasTable($name));
        }
    }
}

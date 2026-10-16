<?php

namespace Tests\Unit\Billing;

use App\Models\App;
use App\Models\Billing\WidgetSubscription;
use App\Services\Billing\WidgetSubscriptionAccessService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WidgetTrialAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(12, 37, 35));
        config([
            'alerts.enabled' => false,
            'database.default' => 'widget_trial_test',
            'database.connections.widget_trial_test' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]);
        DB::purge('widget_trial_test');
        Http::preventStrayRequests();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('apps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('name');
            $table->string('resource_name')->nullable();
            $table->unsignedInteger('setting_id')->nullable();
            $table->integer('status');
            $table->date('expires_tariff_at')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();
        });
        (require database_path('migrations/2026_06_14_020000_create_manual_subscription_tables.php'))->up();
        Schema::table('subscription_plans', fn (Blueprint $table) => $table->string('widget')->nullable());
        DB::table('users')->insert(['id' => 141]);
    }

    #[DataProvider('legacyAccessCases')]
    public function test_first_connection_preserves_existing_access(
        int $appStatus,
        ?string $expiresAt,
        string $subscriptionStatus,
        bool $usable,
    ): void {
        $app = $this->legacyApp($appStatus, $expiresAt);
        $before = $app->refresh()->getRawOriginal();
        $access = app(WidgetSubscriptionAccessService::class);

        $subscription = $access->ensureTrialForWidget(141, 'yclients');

        $this->assertSame($subscriptionStatus, $subscription->status);
        $this->assertSame($expiresAt, $subscription->ends_at?->toDateString());
        $this->assertSame($usable, $subscription->isCurrentlyUsable());
        $this->assertSame($usable, $access->canUse(141, 'yclients'));
        $this->assertNull($subscription->subscription_plan_id);
        $this->assertSame($before, $app->fresh()->getRawOriginal());

        $again = $access->ensureTrialForWidget(141, 'yclients');
        $this->assertSame($subscription->id, $again->id);
        $this->assertSame($expiresAt, $app->fresh()->expires_tariff_at);
        $this->assertSame($appStatus, (int)$app->fresh()->status);
        $this->assertDatabaseCount('widget_subscriptions', 1);
    }

    public static function legacyAccessCases(): array
    {
        return [
            'annual renewal' => [App::STATE_ACTIVE, '2027-08-07', 'active', true],
            'temporarily disabled annual access' => [App::STATE_INACTIVE, '2027-08-07', 'blocked', false],
            'unlimited access' => [App::STATE_ACTIVE, null, 'active', true],
            'blocked unlimited access' => [App::STATE_INACTIVE, null, 'blocked', false],
            'expired access' => [App::STATE_EXPIRES, '2026-10-08', 'expired', false],
            'active flag with expired date' => [App::STATE_ACTIVE, '2026-10-08', 'expired', false],
            'last paid day' => [App::STATE_ACTIVE, '2026-10-09', 'active', true],
        ];
    }

    public function test_new_customer_gets_only_one_trial(): void
    {
        $app = $this->legacyApp(App::STATE_CREATED, null);
        $access = app(WidgetSubscriptionAccessService::class);
        $subscription = $access->ensureTrialForWidget(141, 'yclients');

        $this->assertSame('trial', $subscription->status);
        $this->assertSame('2026-10-16', $subscription->ends_at->toDateString());
        $this->assertSame('2026-10-16', $app->fresh()->expires_tariff_at);
        $this->assertSame(App::STATE_ACTIVE, (int)$app->fresh()->status);

        $this->travel(2)->days();
        $again = $access->ensureTrialForWidget(141, 'yclients');
        $this->assertSame($subscription->id, $again->id);
        $this->assertSame('2026-10-16', $again->ends_at->toDateString());
        $this->assertSame('trial', $again->status);
        $this->assertDatabaseCount('widget_subscriptions', 1);
    }

    #[DataProvider('longerLegacyAccessCases')]
    public function test_reconnection_does_not_overwrite_legacy_renewal_with_a_stale_trial(
        int $appStatus,
        ?string $expiresAt,
        string $subscriptionStatus,
    ): void {
        $app = $this->legacyApp($appStatus, $expiresAt);
        $trial = $this->subscription($app, 'trial', '2026-10-16');

        $subscription = app(WidgetSubscriptionAccessService::class)->ensureTrialForWidget(141, 'yclients');

        $this->assertSame($trial->id, $subscription->id);
        $this->assertSame($subscriptionStatus, $subscription->status);
        $this->assertSame($expiresAt, $subscription->ends_at?->toDateString());
        $this->assertSame($expiresAt, $app->fresh()->expires_tariff_at);
        $this->assertSame($appStatus, (int)$app->fresh()->status);
    }

    public static function longerLegacyAccessCases(): array
    {
        return [
            'annual' => [App::STATE_ACTIVE, '2027-08-07', 'active'],
            'disabled annual' => [App::STATE_INACTIVE, '2027-08-07', 'blocked'],
            'unlimited' => [App::STATE_ACTIVE, null, 'active'],
        ];
    }

    #[DataProvider('existingAccessCases')]
    public function test_reconnection_preserves_existing_subscription_decisions(string $status, ?string $blockedAt): void
    {
        $app = $this->legacyApp(App::STATE_ACTIVE, '2027-08-07');
        $subscription = $this->subscription($app, $status, '2026-10-16', $blockedAt);
        $before = $subscription->refresh()->getRawOriginal();

        app(WidgetSubscriptionAccessService::class)->ensureTrialForWidget(141, 'yclients');

        $this->assertSame($before, $subscription->fresh()->getRawOriginal());
    }

    public static function existingAccessCases(): array
    {
        return [
            'blocked subscription' => ['blocked', '2026-10-08 10:00:00'],
            'blocked trial' => ['trial', '2026-10-08 10:00:00'],
            'cancelled subscription' => ['cancelled', null],
            'paid subscription' => ['active', null],
        ];
    }

    public function test_legacy_renewal_still_updates_the_imported_subscription(): void
    {
        $app = $this->legacyApp(App::STATE_INACTIVE, '2027-08-07');
        $access = app(WidgetSubscriptionAccessService::class);
        $subscription = $access->ensureTrialForWidget(141, 'yclients');
        $app->update(['status' => App::STATE_ACTIVE, 'expires_tariff_at' => '2028-08-07']);

        $access->syncLegacyAppToManualSubscription($app);
        $access->ensureTrialForWidget(141, 'yclients');

        $this->assertSame('active', $subscription->fresh()->status);
        $this->assertSame('2028-08-07', $subscription->fresh()->ends_at->toDateString());
        $this->assertNull($subscription->fresh()->blocked_at);
        $this->assertSame('2028-08-07', $app->fresh()->expires_tariff_at);
    }

    private function legacyApp(int $status, ?string $expiresAt): App
    {
        return App::query()->create([
            'user_id' => 141, 'name' => 'yclients', 'status' => $status,
            'expires_tariff_at' => $expiresAt, 'installed_at' => '2026-05-19 12:19:57',
        ]);
    }

    private function subscription(App $app, string $status, string $endsAt, ?string $blockedAt = null): WidgetSubscription
    {
        return WidgetSubscription::query()->create([
            'user_id' => 141, 'app_id' => $app->id, 'widget' => 'yclients',
            'status' => $status, 'starts_at' => '2026-10-09', 'ends_at' => $endsAt,
            'blocked_at' => $blockedAt,
        ]);
    }
}

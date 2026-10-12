<?php

namespace Tests\Unit\Billing;

use App\Jobs\Core\SendPlatformTechnicalAlert;
use App\Models\App;
use App\Models\Billing\WidgetSubscription;
use App\Models\User;
use App\Services\Billing\WidgetSubscriptionAccessService;
use App\Services\Core\PlatformTechnicalMonitor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WidgetSubscriptionNotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(10, 31));
        config([
            'alerts.enabled' => true,
            'database.default' => 'subscription_notification_test',
            'database.connections.subscription_notification_test' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]);
        DB::purge('subscription_notification_test');
        Queue::fake();
        Http::preventStrayRequests();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
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
        Schema::create('subscription_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->softDeletes();
        });
        Schema::create('widget_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('app_id')->nullable();
            $table->unsignedInteger('subscription_plan_id')->nullable();
            $table->string('widget');
            $table->string('status');
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->date('grace_until')->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::table('users')->insert(['id' => 1, 'name' => 'Test', 'email' => 'client@example.test']);
        DB::table('apps')->insert([
            'id' => 1, 'user_id' => 1, 'name' => 'yclients',
            'status' => App::STATE_ACTIVE, 'expires_tariff_at' => '2026-11-06',
        ]);
        DB::table('widget_subscriptions')->insert([
            'id' => 1, 'user_id' => 1, 'app_id' => 1, 'widget' => 'yclients',
            'status' => WidgetSubscription::STATUS_ACTIVE, 'ends_at' => '2026-11-06',
        ]);
    }

    public function test_manual_deactivation_sends_only_the_explicit_widget_alert(): void
    {
        $app = App::findOrFail(1);
        $app->update(['status' => App::STATE_INACTIVE]);

        $access = app(WidgetSubscriptionAccessService::class);
        $subscription = $access->syncLegacyAppToManualSubscription($app);
        // The admin action reports the explicit cause after synchronizing access.
        app(PlatformTechnicalMonitor::class)->legacyWidgetDeactivated(
            $app,
            (new User)->forceFill(['id' => 3, 'email' => 'operator@example.test']),
        );
        $access->syncLegacyAppToManualSubscription($app);

        Queue::assertPushed(SendPlatformTechnicalAlert::class, 1);
        Queue::assertPushed(SendPlatformTechnicalAlert::class, fn ($job) =>
            $job->title === 'Виджет отключён'
            && str_contains($job->message, 'operator@example.test')
            && $job->context['app_id'] === 1);
        $this->assertSame(WidgetSubscription::STATUS_BLOCKED, $subscription->status);
        $this->assertSame('2026-11-06', $subscription->ends_at->toDateString());
        $this->assertFalse($subscription->isCurrentlyUsable());
        $this->assertSame(App::STATE_INACTIVE, (int) $app->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_overdue_subscription_still_sends_one_expiry_alert(): void
    {
        DB::table('widget_subscriptions')->where('id', 1)->update(['ends_at' => '2026-10-11']);
        $access = app(WidgetSubscriptionAccessService::class);

        $this->assertSame(['processed' => 1, 'expired' => 1, 'errors' => 0], $access->expireOverdueSubscriptions());
        $this->assertSame(['processed' => 0, 'expired' => 0, 'errors' => 0], $access->expireOverdueSubscriptions());
        Queue::assertPushed(SendPlatformTechnicalAlert::class, 1);
        Queue::assertPushed(SendPlatformTechnicalAlert::class, fn ($job) => $job->title === 'Подписка истекла');
        $this->assertSame(WidgetSubscription::STATUS_EXPIRED, WidgetSubscription::findOrFail(1)->status);
        $this->assertSame(App::STATE_EXPIRES, (int) App::findOrFail(1)->status);
    }

    #[DataProvider('nonExpiryChanges')]
    public function test_non_expiry_changes_do_not_send_false_expiry_or_renewal_alerts(array $before, array $changes): void
    {
        DB::table('widget_subscriptions')->where('id', 1)->update($before);
        WidgetSubscription::findOrFail(1)->update($changes);

        Queue::assertNotPushed(SendPlatformTechnicalAlert::class);
    }

    public static function nonExpiryChanges(): array
    {
        return [
            'manual block' => [['status' => 'active'], ['status' => 'blocked', 'blocked_at' => '2026-10-12 10:31:00']],
            'cancel' => [['status' => 'active'], ['status' => 'cancelled']],
            'block timestamp only' => [['status' => 'active'], ['blocked_at' => '2026-10-12 10:31:00']],
            'expiry timestamp corrected' => [['status' => 'expired'], ['blocked_at' => '2026-10-12 10:31:00']],
            'blocked expiry date edited' => [['status' => 'blocked'], ['ends_at' => '2026-11-07']],
            'unrelated edit' => [['status' => 'active'], ['notes' => 'Updated note']],
        ];
    }

    public function test_normal_renewal_notification_is_preserved(): void
    {
        WidgetSubscription::findOrFail(1)->update(['ends_at' => '2026-12-06']);

        Queue::assertPushed(SendPlatformTechnicalAlert::class, 1);
        Queue::assertPushed(SendPlatformTechnicalAlert::class, fn ($job) => $job->title === 'Подписка продлена');
    }

    public function test_reactivation_notification_is_preserved(): void
    {
        DB::table('widget_subscriptions')->where('id', 1)->update([
            'status' => 'blocked', 'blocked_at' => '2026-10-12 09:00:00',
        ]);
        WidgetSubscription::findOrFail(1)->update(['status' => 'active', 'blocked_at' => null]);

        Queue::assertPushed(SendPlatformTechnicalAlert::class, 1);
        Queue::assertPushed(SendPlatformTechnicalAlert::class, fn ($job) => $job->title === 'Подписка продлена');
    }

    public function test_valid_grace_period_does_not_send_expiry_alert(): void
    {
        DB::table('widget_subscriptions')->where('id', 1)->update([
            'ends_at' => '2026-10-11', 'grace_until' => '2026-10-13',
        ]);

        $this->assertSame(
            ['processed' => 0, 'expired' => 0, 'errors' => 0],
            app(WidgetSubscriptionAccessService::class)->expireOverdueSubscriptions(),
        );
        Queue::assertNotPushed(SendPlatformTechnicalAlert::class);
    }
}

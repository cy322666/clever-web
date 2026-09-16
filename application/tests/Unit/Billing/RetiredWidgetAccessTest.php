<?php

namespace Tests\Unit\Billing;

use App\Filament\Resources\Billing\WidgetSubscriptionResource;
use App\Models\App;
use App\Models\Billing\WidgetSubscription;
use App\Models\User;
use App\Services\Billing\WidgetSubscriptionAccessService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RetiredWidgetAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'retired_widget_test',
            'database.connections.retired_widget_test' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]);
        DB::purge('retired_widget_test');

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
        Schema::create('widget_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('app_id')->nullable();
            $table->string('widget');
            $table->string('status');
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->date('grace_until')->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function test_retired_widgets_cannot_use_stale_active_subscriptions_or_receive_trials(): void
    {
        $access = app(WidgetSubscriptionAccessService::class);

        foreach (['alfacrm', 'calculator'] as $widget) {
            $subscription = $this->subscription($widget, App::STATE_ACTIVE);
            $before = $subscription->getRawOriginal();

            $this->assertFalse($access->canUse(1, $widget));
            $this->assertFalse($access->statusFor(1, $widget)['active']);
            $this->assertNull($access->ensureTrialForWidget(2, $widget));
            $this->assertNull($access->syncLegacyAppToManualSubscription($subscription->app));
            $this->assertSame($before, $subscription->fresh()->getRawOriginal());
        }

        $this->assertSame(2, WidgetSubscription::query()->count());
    }

    public function test_subscription_sync_does_not_reactivate_retired_installations_and_keeps_supported_widgets_working(): void
    {
        $access = app(WidgetSubscriptionAccessService::class);

        foreach (['alfacrm', 'calculator', 'tilda', 'default'] as $widget) {
            $subscription = $this->subscription($widget, App::STATE_INACTIVE);
            $access->syncSubscriptionToLegacyApp($subscription);

            $supported = in_array($widget, ['tilda', 'default'], true);
            $expected = $supported ? App::STATE_ACTIVE : App::STATE_INACTIVE;
            $this->assertSame($expected, (int)$subscription->app->fresh()->status);
            $this->assertSame($supported, $access->canUse(1, $widget));
        }

        $this->assertSame(4, App::query()->count());
        $this->assertSame(4, WidgetSubscription::query()->count());
    }

    public function test_root_cannot_edit_retired_subscriptions_but_can_still_edit_supported_subscriptions(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'is_root' => true]));

        foreach (['alfacrm', 'calculator'] as $widget) {
            $record = (new WidgetSubscription)->forceFill(['widget' => $widget]);
            $this->assertFalse(WidgetSubscriptionResource::canEdit($record));
            $this->assertArrayNotHasKey($widget, WidgetSubscriptionResource::widgetOptions());
        }

        $this->assertTrue(WidgetSubscriptionResource::canEdit((new WidgetSubscription)->forceFill(['widget' => 'tilda'])));
        $this->assertTrue(WidgetSubscriptionResource::canEdit((new WidgetSubscription)->forceFill(['widget' => 'default'])));
        $this->assertArrayHasKey('tilda', WidgetSubscriptionResource::widgetOptions());
    }

    private function subscription(string $widget, int $appStatus): WidgetSubscription
    {
        $appId = DB::table('apps')->insertGetId([
            'user_id' => 1,
            'name' => $widget,
            'status' => $appStatus,
        ]);
        $id = DB::table('widget_subscriptions')->insertGetId([
            'user_id' => 1,
            'app_id' => $appId,
            'widget' => $widget,
            'status' => WidgetSubscription::STATUS_ACTIVE,
            'ends_at' => now()->addMonth()->toDateString(),
        ]);

        return WidgetSubscription::query()->findOrFail($id);
    }
}

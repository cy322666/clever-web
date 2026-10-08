<?php

namespace Tests\Unit\Billing;

use App\Console\Commands\Billing\SyncSubscriptionPlans;
use App\Filament\Resources\Billing\SubscriptionPlanResource;
use App\Filament\Resources\Integrations\TildaResource;
use App\Filament\Resources\Integrations\YClients\YClientsResource;
use App\Models\Billing\SubscriptionPlan;
use App\Models\Integrations\Finder\Setting as FinderSetting;
use App\Models\Integrations\Tilda\Setting as TildaSetting;
use App\Models\Integrations\YClients\Setting;
use App\Support\Integrations\PricingView;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Console\Tester\CommandTester;

class YClientsPricingTest extends TestCase
{
    private $previousContainer;
    private $previousFacade;
    private $previousResolver;
    private $previousEvents;
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousEvents = Model::getEventDispatcher();
        $this->app = new Application(dirname(__DIR__, 3));
        $this->app->instance('config', new Repository(['integrations' => ['definitions' => [
            'yclients' => ['resource' => YClientsResource::class, 'title' => 'YClients'],
            'tilda' => ['resource' => TildaResource::class, 'title' => 'Tilda'],
        ]]]));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $db = new Manager($this->app);
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->bootEloquent();
        Model::unsetEventDispatcher();
        $db->getConnection()->getSchemaBuilder()->create('subscription_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('widget');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('price_label')->nullable();
            $table->integer('price_rub')->nullable();
            $table->integer('period_days')->nullable();
            $table->text('features')->nullable();
            $table->text('limits')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        $this->previousResolver !== null ? Model::setConnectionResolver($this->previousResolver) : Model::unsetConnectionResolver();
        $this->previousEvents !== null ? Model::setEventDispatcher($this->previousEvents) : Model::unsetEventDispatcher();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacade);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_sidebar_has_four_periods_with_matching_monthly_prices(): void
    {
        $this->assertSame(['3_month', '6_month', '12_month', '24_month'], array_keys(Setting::$cost));
        $html = PricingView::sidebarHtml(Setting::$cost)->toHtml();
        preg_match_all('/class="integration-pricing__period">([^<]+)/u', $html, $labels);
        $this->assertSame(['3 месяца', '6 месяцев', '12 месяцев', '24 месяца'], $labels[1]);
        foreach (['7 990 руб.', '14 900 руб.', '24 900 руб.', '39 900 руб.',
            '2 663 руб./мес.', '2 483 руб./мес.', '2 075 руб./мес.', '1 663 руб./мес.'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertStringNotContainsString('1 месяц', $html);
        $this->assertStringNotContainsString('₽', $html);
    }

    public function test_existing_widgets_keep_their_periods_and_account_pricing(): void
    {
        $html = PricingView::sidebarHtml(TildaSetting::$cost)->toHtml();
        preg_match_all('/class="integration-pricing__period">([^<]+)/u', $html, $labels);
        $this->assertSame(['1 месяц', '6 месяцев', '12 месяцев'], $labels[1]);
        foreach (TildaSetting::$cost as $price) $this->assertStringContainsString($price, $html);

        $finder = PricingView::sidebarHtml(FinderSetting::$cost, false, 5)->toHtml();
        $this->assertStringContainsString('До 5 пользователей включительно', $finder);
        $this->assertStringContainsString('249 руб в месяц', $finder);
        $this->assertStringNotContainsString('<div class="integration-pricing__note">', $finder);
    }

    public function test_new_cards_escape_prices_and_can_hide_monthly_notes(): void
    {
        $cost = Setting::$cost;
        $cost['3_month'] = '<img src=x onerror=alert(1)>7 990 руб.';
        $html = PricingView::sidebarHtml($cost, false)->toHtml();
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;img', $html);
        $this->assertStringNotContainsString('<div class="integration-pricing__note">', $html);
    }

    public function test_sync_updates_only_yclients_and_preserves_obsolete_plan_history(): void
    {
        $old = $this->plan('yclients-1-month', 'yclients', 2990, 30);
        $six = $this->plan('yclients-6-month', 'yclients', 14900, 180);
        $year = $this->plan('yclients-12-month', 'yclients', 24900, 365);
        $other = $this->plan('tilda-1-month', 'tilda', 4321, 30)->fresh()->toArray();
        $this->sync(['--widget' => 'yclients']);

        $plans = SubscriptionPlan::query()->where('widget', 'yclients')->active()->orderBy('period_days')->get();
        $this->assertSame([90, 180, 365, 730], $plans->pluck('period_days')->all());
        $this->assertSame([7990, 14900, 24900, 39900], $plans->pluck('price_rub')->all());
        $this->assertSame(array_values(Setting::$cost), $plans->pluck('price_label')->all());
        $this->assertSame($six->id, $plans[1]->id);
        $this->assertSame($year->id, $plans[2]->id);
        $this->assertFalse($old->fresh()->is_active);
        $this->assertSame(2990, $old->fresh()->price_rub);
        $this->assertNull($old->fresh()->deleted_at);
        $this->assertSame($other, SubscriptionPlan::query()->find($other['id'])->toArray());
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->plan('yclients-1-month', 'yclients', 2990, 30);
        $before = SubscriptionPlan::all()->toArray();
        $tester = $this->sync(['--widget' => 'yclients', '--dry-run' => true]);
        $this->assertSame($before, SubscriptionPlan::all()->toArray());
        $this->assertStringContainsString('create yclients-24-month', $tester->getDisplay());
        $this->assertStringContainsString('deactivate yclients-1-month', $tester->getDisplay());
    }

    public function test_repeated_and_full_sync_do_not_restore_monthly_yclients_plan(): void
    {
        $old = $this->plan('yclients-1-month', 'yclients', 2990, 30);
        $this->sync(['--widget' => 'yclients']);
        $ids = SubscriptionPlan::query()->where('widget', 'yclients')->pluck('id')->all();
        $this->sync([]);
        $this->assertSame($ids, SubscriptionPlan::query()->where('widget', 'yclients')->pluck('id')->all());
        $this->assertFalse($old->fresh()->is_active);
        $this->assertSame([30, 180, 365], SubscriptionPlan::query()->where('widget', 'tilda')
            ->orderBy('period_days')->pluck('period_days')->all());
    }

    public function test_unknown_widget_does_not_write_plans(): void
    {
        $command = new SyncSubscriptionPlans();
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $this->assertSame(1, $tester->execute(['--widget' => 'missing']));
        $this->assertSame(0, SubscriptionPlan::query()->count());
    }

    public function test_billing_labels_match_sidebar_for_new_periods(): void
    {
        $period = new ReflectionMethod(SubscriptionPlanResource::class, 'periodLabel');
        $monthly = new ReflectionMethod(SubscriptionPlanResource::class, 'monthlyPriceLabel');
        foreach ([[90, 7990, '3 месяца', '2 663'], [730, 39900, '24 месяца', '1 663']] as [$days, $price, $label, $amount]) {
            $plan = new SubscriptionPlan(['widget' => 'yclients', 'period_days' => $days, 'price_rub' => $price]);
            $this->assertSame($label, $period->invoke(null, $plan));
            $this->assertSame('примерно '.$amount.' руб./мес.', $monthly->invoke(null, $plan));
        }
    }

    private function plan(string $slug, string $widget, int $price, int $days): SubscriptionPlan
    {
        return SubscriptionPlan::create(['slug' => $slug, 'widget' => $widget, 'name' => $slug,
            'price_rub' => $price, 'period_days' => $days, 'is_active' => true]);
    }

    private function sync(array $options): CommandTester
    {
        $command = new SyncSubscriptionPlans();
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $this->assertSame(0, $tester->execute($options), $tester->getDisplay());

        return $tester;
    }
}

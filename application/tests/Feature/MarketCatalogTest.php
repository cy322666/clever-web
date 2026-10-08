<?php

namespace Tests\Feature;

use App\Filament\App\Pages\Dashboard;
use App\Filament\App\Widgets\Market;
use App\Models\App;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class MarketCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'market_test',
            'database.connections.market_test' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]);
        DB::purge('market_test');

        Schema::create('apps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('name');
            $table->string('resource_name')->nullable();
            $table->integer('status')->default(App::STATE_CREATED);
            $table->date('expires_tariff_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'name']);
        });

        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->actingAs((new User)->forceFill([
            'id' => 1,
            'crm_provider' => 'amocrm',
            'industry' => 'veterinary',
        ]));
    }

    public function test_market_groups_widgets_without_losing_expiration_or_tenant_scope(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 28)->startOfDay());
        $expired = App::create([
            'user_id' => 1,
            'name' => 'distribution',
            'status' => App::STATE_ACTIVE,
            'expires_tariff_at' => now()->subDays(34)->toDateString(),
        ]);
        $otherUserApp = App::create([
            'user_id' => 2,
            'name' => 'distribution',
            'status' => App::STATE_ACTIVE,
        ]);

        $component = Livewire::test(Market::class)
            ->assertSee('Универсальные')
            ->assertSee('Отраслевые')
            ->assertSee('Истёк 34 дн.')
            ->assertSee(route('integrations.open', ['app' => $expired->id]), false)
            ->assertDontSee(route('integrations.open', ['app' => $otherUserApp->id]), false);

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$component->html());
        $xpath = new DOMXPath($dom);
        $titles = fn (string $category): array => array_map(
            fn ($node): string => trim($node->textContent),
            iterator_to_array($xpath->query('//section[@data-category="'.$category.'"]//h3')),
        );

        $this->assertEqualsCanonicalizing(
            ['Контроль ответов', 'Тильда', 'Распределение', 'Импорт Excel', 'Потоки'],
            $titles('universal'),
        );
        $this->assertSame(['Vetmanager', 'SQNS', 'YClients'], $titles('industry'));
        $this->assertSame(App::STATE_ACTIVE, $expired->fresh()->status);
        $this->assertSame(1, App::where('user_id', 2)->count());
    }

    public function test_cards_show_icons_names_and_actions_without_marketing_copy_or_crm_badges(): void
    {
        $component = Livewire::test(Market::class)
            ->assertSee('Подключить')
            ->assertDontSee('Можно подключить')
            ->assertDontSee('amoCRM')
            ->assertDontSee('КАТАЛОГ CLEVERCRM')
            ->assertDontSee('Показываем только те решения')
            ->assertDontSee('Инструменты для вашей CRM')
            ->assertDontSee('Для вашей сферы');

        foreach (App::all() as $app) {
            $component->assertDontSee(App::getTooltipText($app->name));
        }

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$component->html());
        $xpath = new DOMXPath($dom);
        $cards = $xpath->query('//a[@class="clever-market-card"]');

        $this->assertCount(8, $cards);
        foreach ($cards as $card) {
            $this->assertSame(1, $xpath->query('.//span[@class="clever-market-card__icon" and @aria-hidden="true"]/svg', $card)->length);
            $this->assertSame(1, $xpath->query('.//h3', $card)->length);
            $this->assertSame(0, $xpath->query('.//p', $card)->length);
        }
        $this->assertSame(0, $xpath->query('//*[contains(@class, "fi-badge")]')->length);
    }

    public function test_dashboard_has_no_visible_heading_but_keeps_its_browser_title(): void
    {
        $page = new Dashboard;

        $this->assertSame('', $page->getHeading());
        $this->assertSame('Интеграции', $page->getTitle());
    }

    public function test_crm_filter_applies_to_both_sections_even_for_existing_apps(): void
    {
        config([
            'integrations.definitions.finder.crm_providers' => ['kommo'],
            'integrations.definitions.vetmanager.crm_providers' => ['kommo'],
        ]);
        foreach (['finder', 'vetmanager'] as $name) {
            App::create(['user_id' => 1, 'name' => $name, 'status' => App::STATE_ACTIVE]);
        }

        Livewire::test(Market::class)
            ->assertSee('Распределение')
            ->assertSee('SQNS')
            ->assertDontSee('Контроль ответов')
            ->assertDontSee('Vetmanager');

        $this->assertSame(2, App::whereIn('name', ['finder', 'vetmanager'])->count());
    }

    public function test_empty_crm_catalog_does_not_show_empty_sections(): void
    {
        auth()->user()->crm_provider = 'kommo';

        Livewire::test(Market::class)
            ->assertSee('Для Kommo пока нет интеграций')
            ->assertDontSee('Универсальные')
            ->assertDontSee('Отраслевые');

        $this->assertSame(0, App::count());
    }
}

<?php

namespace Tests\Feature\Integrations;

use App\Filament\Catalog\Pages\WidgetShow as WidgetPage;
use App\Filament\Catalog\Widgets\WidgetShow as WidgetTable;
use App\Models\User;
use App\Models\Widgets\Widget;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ArchivedWidgetCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        Schema::create('widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('slug');
            $table->boolean('is_published');
        });
        DB::table('widgets')->insert([
            ['slug' => 'tilda', 'is_published' => true],
            ['slug' => 'alfacrm', 'is_published' => false],
            ['slug' => 'calculator', 'is_published' => false],
        ]);
    }

    public function test_public_catalog_queries_only_return_published_widgets(): void
    {
        $table = new class extends WidgetTable {
            public function visibleSlugs(): array
            {
                return $this->getFilteredQuery()->pluck('slug')->all();
            }
        };

        $this->assertSame(['tilda'], $table->visibleSlugs());
        $this->assertSame(['tilda'], $table->getWidgetsProperty()->pluck('slug')->all());
    }

    public function test_archived_detail_is_unavailable_to_guests_and_regular_users(): void
    {
        $regularUser = (new User)->forceFill(['id' => 42, 'is_root' => false]);
        Auth::shouldReceive('user')->times(6)->andReturn(null, null, null, $regularUser, $regularUser, $regularUser);

        foreach (['guest', 'regular user'] as $viewer) {
            $published = new WidgetPage;
            $published->mount('tilda');
            $this->assertSame('tilda', $published->record->slug);

            foreach (['alfacrm', 'calculator'] as $slug) {
                try {
                    (new WidgetPage)->mount($slug);
                    $this->fail("Archived widget {$slug} was available to {$viewer}.");
                } catch (ModelNotFoundException $exception) {
                    $this->assertSame(Widget::class, $exception->getModel());
                }
            }
        }
    }

    public function test_root_can_preview_archived_widget_details(): void
    {
        Auth::shouldReceive('user')->twice()->andReturn((new User)->forceFill(['id' => 1, 'is_root' => true]));

        foreach (['alfacrm', 'calculator'] as $slug) {
            $page = new WidgetPage;
            $page->mount($slug);

            $this->assertSame($slug, $page->record->slug);
            $this->assertFalse($page->record->is_published);
        }
    }
}

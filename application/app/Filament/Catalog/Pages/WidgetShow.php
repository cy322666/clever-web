<?php

namespace App\Filament\Catalog\Pages;

use App\Models\Widgets\Widget;
use Filament\Pages\Page;
use Filament\Panel;
use Illuminate\Support\Facades\Auth;

class WidgetShow extends Page
{
    protected static bool $shouldRegisterNavigation = false;
    protected static string $routePath = 'widgets/{slug}';
    protected static ?string $title = '';

    public Widget $record;

    public static function getRoutePath(Panel $panel): string
    {
        return static::$routePath;
    }

    public function getView(): string
    {
        return 'filament.catalog.pages.widget-show';
    }

    public function mount(string $slug): void
    {
        $query = Widget::query()->where('slug', $slug);

        if (!Auth::check()) {
            $query->where('is_published', true);
        }

        $this->record = $query->firstOrFail();
    }
}

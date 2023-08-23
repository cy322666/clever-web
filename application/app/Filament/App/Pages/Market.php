<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Widgets\AlfaPreview;
use App\Filament\App\Widgets\BizonPreview;
use App\Filament\App\Widgets\GetCoursePreview;
use Filament\Pages\Page;

class Market extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.app.pages.market';

    protected static ?string $title = 'Магазин интеграций';

    protected function getHeaderWidgets(): array
    {
        return [
            BizonPreview::class,
            GetCoursePreview::class,
            AlfaPreview::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int | array
    {
        return 3;
    }

    public static function getSort()
    {
        return 1;
    }

    public static function canView()
    {
        return 1;
    }

    public static function getDefaultProperties()
    {
        return [];
    }
}

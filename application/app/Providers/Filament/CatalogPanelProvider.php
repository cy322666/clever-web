<?php

namespace App\Providers\Filament;

use App\Filament\Catalog\Widgets\CaseCards;
use App\Filament\Catalog\Pages\CaseCatalog;
use App\Filament\Catalog\Pages\CaseShow;
use App\Filament\Catalog\Pages\WidgetCatalog;
use App\Filament\Catalog\Pages\WidgetShow;
use App\Filament\Catalog\Widgets\WidgetShow as WidgetShowWidget;
use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\VerifyCsrfToken;
use Exception;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class CatalogPanelProvider extends PanelProvider
{
    /**
     * @throws Exception
     */
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('catalog')
            ->path('catalog') // например, публичный корень
            ->colors([
                'primary' => '#FF4E36',
            ])
            ->pages([
                CaseCatalog::class,
                CaseShow::class,
                WidgetCatalog::class,
                WidgetShow::class,
            ])
            ->widgets([
                CaseCards::class,
                WidgetShowWidget::class,
            ])
            ->discoverPages(
                in: app_path('Filament/Catalog/Pages'),
                for: 'App\\Filament\\Catalog\\Pages',
            )
            ->sidebarCollapsibleOnDesktop(true)
            ->sidebarFullyCollapsibleOnDesktop(false)
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
            ]);
        // ВАЖНО: убираем auth middleware
//            ->authMiddleware([
        // пусто
//            ]);
    }
}

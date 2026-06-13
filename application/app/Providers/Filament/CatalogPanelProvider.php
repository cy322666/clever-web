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
use Filament\FontProviders\GoogleFontProvider;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
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
            ->brandName('CleverCRM')
            ->brandLogo(asset('logo/full_logo.png'))
            ->brandLogoHeight('2rem')
            ->favicon(asset('logo/clever_mini_logo.png'))
            ->font(
                'Manrope',
                'https://fonts.googleapis.com/css2?family=Barlow:wght@300;400;500;600;700&family=Manrope:wght@300;400;500;600;700;800;900&display=swap',
                GoogleFontProvider::class,
            )
            ->colors([
                'primary' => Color::hex('#ff6a00'),
                'gray' => Color::Stone,
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

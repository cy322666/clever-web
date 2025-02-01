<?php

namespace App\Providers\Filament;

use App\Filament\App\Pages\Market;
use App\Filament\Resources\Core\LogResource;
use App\Filament\Resources\Core\UserResource;
use App\Filament\Resources\Integrations\Alfa\TransactionResource;
use App\Filament\Resources\Integrations\Bizon\WebinarResource;
use App\Filament\Resources\Integrations\Dadata\InfoResource;
use App\Filament\Resources\Integrations\DocResource;
use App\Filament\Resources\Integrations\Tilda\FormResource;
use App\Models\User;
use Exception;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use ShuvroRoy\FilamentSpatieLaravelBackup\FilamentSpatieLaravelBackupPlugin;
use ShuvroRoy\FilamentSpatieLaravelBackup\Pages\Backups;

class AppPanelProvider extends PanelProvider
{
    /**
     * @throws Exception
     */
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('app')
            ->path('')
            ->login()
            ->registration()
//            ->emailVerification()
//            ->profile(EditUser::class)
            ->colors([
                'primary' => Color::Orange,
            ])
            ->discoverPages(in: app_path('Filament/App/Pages'), for: 'App\\Filament\\App\\Pages')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverWidgets(in: app_path('Filament/App/Widgets'), for: 'App\\Filament\\App\\Widgets')
            ->databaseNotifications()
            ->navigation(function (NavigationBuilder $builder): NavigationBuilder {

                return $builder->groups([

                    NavigationGroup::make('')
                        ->items([
                            NavigationItem::make('Home')
                                ->label('Аккаунт')
                                ->icon('heroicon-o-home')
                                ->url(fn (): string => UserResource::getUrl('view', ['record' => User::first()])),

                            NavigationItem::make('Market')
                                ->label('Магазин')
                                ->icon('heroicon-o-shopping-bag')
                                ->url(fn (): string => Market::getUrl()),
                        ]),

//                    NavigationGroup::make('')
//                        ->items([
//                            NavigationItem::make('Бизон')
//                                ->label('Бизон')
//                                ->icon('heroicon-o-academic-cap')
//                                ->url(fn (): string => WebinarResource::getUrl())
//                                ->hidden(fn() => !Auth::user()->is_root),
//
//                            NavigationItem::make('Тильда')
//                                ->label('Тильда')
//                                ->icon('heroicon-o-identification')
//                                ->url(fn (): string => FormResource::getUrl())
//                                ->hidden(fn() => !Auth::user()->is_root),
//
//                            NavigationItem::make('Альфа')
//                                ->label('Альфа')
//                                ->icon('heroicon-o-bars-3-bottom-left')
//                                ->url(fn (): string => TransactionResource::getUrl())
//                                ->hidden(fn() => !Auth::user()->is_root),
//
//                            NavigationItem::make('Инфо')
//                                ->label('Инфо')
//                                ->icon('heroicon-o-magnifying-glass')
//                                ->url(fn (): string => InfoResource::getUrl())
//                                ->hidden(fn() => !Auth::user()->is_root),
//                        ]),
//
//                    NavigationGroup::make('')
//                        ->items([
//                            NavigationItem::make('Доки')
//                                ->label('Доки')
//                                ->icon('heroicon-o-academic-cap')
//                                ->url(fn (): string => DocResource::getUrl('edit', ['record' => 1]))
//                                ->hidden(fn() => !Auth::user()->is_root),
//                        ]),
                ]);
            })
            ->plugin(
                FilamentSpatieLaravelBackupPlugin::make()
                    ->usingPage(Backups::class)
                    ->usingQueue('backups')
            )
            ->globalSearch(false)
            ->breadcrumbs(false)
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->sidebarCollapsibleOnDesktop();
    }
}

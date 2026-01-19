<?php

namespace App\Providers\Filament;

use App\Filament\App\Pages\AppStats;
use App\Filament\App\Pages\Backup;
use App\Filament\App\Pages\Dashboard;
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
use Tapp\FilamentAuthenticationLog\FilamentAuthenticationLogPlugin;

class AppPanelProvider extends PanelProvider
{
    /**
     * @throws Exception
     */
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('app')
            ->path('panel')
            ->login()
            ->registration()
            ->passwordReset()
//            ->emailVerification()
//            ->profile(UserResource\Pages\EditUser::class)//TODO
            ->colors([
                'primary' => Color::Amber,//TODO
            ])
            ->plugins([
                FilamentAuthenticationLogPlugin::make()
                    ->panelName('app'),

                FilamentSpatieLaravelBackupPlugin::make()
                    ->usingPage(Backup::class)
                    ->authorize(fn (): bool => auth()->user()->is_root),

            ])
            ->pages([
                Dashboard::class,
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
                                ->url(fn (): string => Dashboard::getUrl()),
                        ]),
                ]);
            })
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

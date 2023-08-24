<?php

namespace App\Providers\Filament;

use App\Filament\App\Pages\Billing;
use App\Filament\App\Pages\Market;
use App\Filament\App\Pages\Profile;
use App\Filament\App\Widgets\AlfaPreview;
use App\Filament\App\Widgets\BizonPreview;
use App\Filament\App\Widgets\GetCoursePreview;
use App\Filament\Resources\Core\AccountResource;
use App\Filament\Resources\Core\UserResource;
use App\Filament\Resources\Core\UserResource\Pages\EditUser;
use App\Http\Middleware\RootMiddleware;
use App\Models\User;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AppPanelProvider extends PanelProvider
{
    /**
     * @throws \Exception
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
//            ->navigationItems([
//                NavigationItem::make('Analytics')
//                    ->url('https://filament.pirsch.io', shouldOpenInNewTab: true)
//                    ->icon('heroicon-o-presentation-chart-line')
//                    ->group('Reports')
//                    ->sort(3),
//
//                NavigationItem::make('Analytics')
//                    ->url('https://filament.pirsch.io', shouldOpenInNewTab: true)
//                    ->icon('heroicon-o-presentation-chart-line')
//                    ->group('Reports')
//                    ->sort(3),
//                NavigationItem::make('dashboard')
//                    ->label(fn (): string => __('filament-panels::pages/dashboard.title'))
//                    ->url(fn (): string => EditUser::getUrl())
//                    ->isActiveWhen(fn () => request()->routeIs('filament.admin.pages.dashboard')),
                // ...
//            ])
            ->navigation(function (NavigationBuilder $builder): NavigationBuilder {
                return $builder->items([

                    NavigationItem::make('Home')
                        ->label('Аккаунт')
                        ->icon('heroicon-o-home')
//                        ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.pages.dashboard'))
                        ->url(fn (): string => UserResource::getUrl('view', ['record' => User::first()])),

                    NavigationItem::make('Market')
                        ->label('Магазин')
                        ->icon('heroicon-o-shopping-bag')
//                        ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.pages.dashboard'))
                        ->url(fn (): string => Market::getUrl()),
//                    ...UserResource::getNavigationItems(),

                // UserResource::getUrl('view', ['record' => Auth::user()]
//                    ...UserResource::getNavigationItems(),

                ]);
            })
//            ->navigationItems([
//                NavigationItem::make('Analytics')
//                    ->url(UserResource::getUrl('edit', ['record' => User::first()]))
//                    ->icon('heroicon-o-presentation-chart-line')
//                    ->group('Reports')
//                    ->sort(3)
//            ->userMenuItems([
//                MenuItem::make()
//                    ->label('Settings')
//                    ->url(route('filament.admin.pages.settings'))
//                    ->icon('heroicon-o-cog-6-tooth'),
//                // ...
//            ])
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

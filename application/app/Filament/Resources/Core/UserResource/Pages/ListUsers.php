<?php

namespace App\Filament\Resources\Core\UserResource\Pages;

use App\Filament\App\Pages\ApiRequests;
use App\Filament\App\Pages\AppStats;
use App\Filament\Resources\Core\UserResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    public function mountCanAuthorizeResourceAccess(): void
    {
        abort_unless(Auth::check() && (bool)Auth::user()?->is_root, 403);
    }

    protected function getActions(): array
    {
        $actions = [];

//        if (
//            config('features.panel_actions.api_requests', true)
//            && Route::has('filament.app.pages.api-requests')
//        ) {
//            $actions[] = Action::make('api_requests')
//                ->label('Api запросы')
//                ->url(fn(): string => ApiRequests::getUrl())
//                ->openUrlInNewTab()
//                ->color(Color::Blue);
//        }

        if (config('features.panel_actions.auth_logs', true)) {
            $actions[] = Action::make('auths')
                ->label('Авторизации')
                ->url(env('APP_URL') . '/panel/authentication-logs')
                ->openUrlInNewTab()
                ->color(Color::Green);
        }

        if (config('features.panel_actions.apps_stats', true)) {
            $actions[] = Action::make('apps')
                ->label('Приложения')
                ->url(AppStats::getUrl())
                ->openUrlInNewTab()
                ->color(Color::Green);
        }

        return $actions;
    }
}

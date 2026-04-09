<?php

namespace App\Filament\Resources\Core\UserResource\Pages;

use App\Filament\Resources\Core\UserResource;
use App\Filament\Resources\Core\UserResource\Widgets\UserAccountOverview;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected static ?string $title = 'Аккаунт';

    public function mountCanAuthorizeResourceAccess(): void
    {
        abort_unless(Auth::check(), 403);
    }

    protected function getActions(): array
    {
        return [
            Action::make('root')
                ->label('Монитор')
                ->icon('heroicon-o-chart-bar-square')
                ->url(UserResource::getUrl())
                ->hidden(fn() => !Auth::user()->is_root),
        ];
    }

    public function mount(int|string $record): void
    {
        if (!Auth::user()?->is_root && Auth::id() !== (int)$record) {
            $this->redirect(UserResource::getUrl('view', ['record' => Auth::id()]));

            return;
        }

        parent::mount($record);
    }

    protected function getHeaderWidgets(): array
    {
        return [
            UserAccountOverview::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    protected function authorizeAccess(): void
    {
        if (!Auth::user()->is_root && Auth::id() !== $this->record->id) {

            $this->redirect(UserResource::getUrl('view', ['record' => Auth::id()]));
        }
    }
}

<?php

namespace App\Filament\Resources\Core\UserResource\Pages;

use App\Filament\Resources\Core\UserResource;
use App\Filament\Resources\Core\UserResource\Widgets\UserAccountOverview;
use App\Helpers\Actions\UpdateButton;
use App\Helpers\Traits\SyncAmoCRMPage;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Colors\Color;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class ViewUser extends ViewRecord
{
    use SyncAmoCRMPage;

    protected static string $resource = UserResource::class;

    protected static ?string $title = 'Аккаунт';

    public function mountCanAuthorizeResourceAccess(): void
    {
        abort_unless(Auth::check(), 403);
    }

    protected function getActions(): array
    {
        $account = $this->record->account;

        $actions = [];

        if ($account) {
            $actions[] = UpdateButton::amoCRMAuthButton($account);
        } else {
            $actions[] = Action::make('amocrmMissing')
                ->label('amoCRM не инициализирована')
                ->tooltip('Для пользователя не создана запись account')
                ->color(Color::Gray)
                ->disabled();
        }

        if ((bool)$account?->active) {
            $actions[] = UpdateButton::amoCRMSyncButton(
                $account,
                fn() => $this->amocrmUpdate(),
            );
        }

        $actions[] = ActionGroup::make([
            Action::make('root')
                ->label('Монитор')
                ->url(UserResource::getUrl()),
            Action::make('widgets')
                ->label('Виджеты')
                ->url(url('/catalog/widgets')),
            Action::make('cases')
                ->label('Кейсы')
                ->url(url('/catalog/cases')),
        ])
            ->label('Навигация')
            ->icon('heroicon-o-ellipsis-horizontal')
            ->color(Color::Gray)
            ->hidden(fn() => !Auth::user()->is_root);

        return $actions;
    }

    public function mount(int|string $record): void
    {
        if (!Auth::user()?->is_root && Auth::id() !== (int)$record) {
            $this->redirect(UserResource::getUrl('view', ['record' => Auth::id()]));

            return;
        }

        parent::mount($record);

        $auth = Request::get('auth');

        if ($auth === '0')
            Notification::make()
                ->title('amoCRM не подключена. Подключите или обратитесь в чат поддержки')
                ->warning()
                ->send();

        if ($auth === '1')
            Notification::make()
                ->title('amoCRM успешно подключена')
                ->success()
                ->send();
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

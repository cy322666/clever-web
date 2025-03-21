<?php

namespace App\Filament\Resources\Core\UserResource\Pages;

use App\Filament\Resources\Core\UserResource;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Account;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Colors\Color;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Request;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected static ?string $title = 'Аккаунт';

    protected function getActions(): array
    {
        return [
            Actions\Action::make('amocrmAuth')
                ->action('amocrmAuth')
                ->color(fn() => $this->record->account->active ? Color::Green : Color::Red)
                ->label(fn() => $this->record->account->active ? 'amoCRM Подключена' : 'Подключить amoCRM')
                ->disabled(fn() => $this->record->account->active),

            Actions\Action::make('activeUpdate')
                ->action('amocrmUpdate')
                ->label('Синхронизировать')
                ->disabled(fn() => !$this->record->account->active),

            Actions\Action::make('breakAuth')
                ->action('breakAuth')
                ->color(Color::Gray)
                ->label('Отозвать авторизацию')
                ->disabled(fn() => !$this->record->account->active),

           Actions\Action::make('root')
               ->label('Монитор')
               ->url(UserResource::getUrl())
               ->color(Color::Gray)
               ->hidden(fn() => !Auth::user()->is_root),
        ];
    }

    public function mount(int|string $record): void
    {
        if (Auth::id() !== (int)$record) {

            $this->redirect(UserResource::getUrl('view', ['record' => Auth::id()]));
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
        return [];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 3; // TODO: Change the autogenerated stub
    }

    public function amocrmAuth(): void
    {
        $account = Auth::user()->account;

        if (!$account->active) {

            Redirect::to('https://www.amocrm.ru/oauth/?state='.Auth::user()->uuid.'&mode=popup&client_id='.config('services.amocrm.client_id'));

        } else
            Notification::make()
                ->title('amoCRM уже подключена')
                ->warning()
                ->send();
    }

    public function breakAuth()
    {
        $account = Auth::user()->account;

        if ($account->active) {

            $account->code = null;
            $account->access_token = null;
            $account->subdomain = null;
            $account->refresh_token = null;
            $account->client_id = null;
            $account->client_secret = null;
            $account->zone = null;
            $account->active = false;
            $account->save();

            Notification::make()
                ->title('Авторизация отозвана')
                ->warning()
                ->send();
        }
    }

    /**
     * @throws \Exception
     */
    public function amocrmUpdate(): void
    {
        $account = Auth::user()->account;

        $amoApi = new Client($account);

        if (!$amoApi->auth)

            $amoApi->init();

        if ($amoApi->auth) {

            Artisan::call('app:sync', ['account' => $account->id]);

            Notification::make()
                ->title('Успешно обновлено')
                ->success()
                ->send();
        } else
            Notification::make()
                ->title('Ошибка авторизации')
                ->danger()
                ->send();
    }

    protected function authorizeAccess(): void
    {
        if (!Auth::user()->is_root && Auth::id() !== $this->record->id) {

            $this->redirect(UserResource::getUrl('view', ['record' => Auth::id()]));
        }
    }
}

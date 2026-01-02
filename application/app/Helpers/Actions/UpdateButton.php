<?php

namespace App\Helpers\Actions;

use App\Models\App;
use App\Models\Core\Account;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Support\Colors\Color;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

abstract class UpdateButton
{
    /*
     * кнопка включить/выключить
     *
     * record = setting
     */
    public static function getAction(Model $record): Action
    {
        //TODO тут есть что доработать
        return Action::make('activeUpdate')
            ->action(
                function (Model $record) {

                    $app = $record->app;

                    if ($record->status == App::STATE_CREATED && is_null($record->install_at))
                        $app->installed_at = Carbon::now();

                    $record->active = $app->status != App::STATE_EXPIRES ? !$record->active : App::STATE_EXPIRES;
                    $record->save();

//                    $app->setStatusWithActive($record->refresh(), $app);

                    //TODO не работает
//                    $app->sendNotificationStatus();
            })
            ->color(fn() => $record->active ? Color::Red : Color::Green)
            ->label(fn() => $record->active ? 'Выключить' : 'Включить');
    }

    //кнопка для синхронизации с амо
    public static function amoCRMSyncButton(Account $account): Action
    {
        return Action::make('activeUpdate')
            ->action('amocrmUpdate')
            ->label('amoCRM')
            ->icon('heroicon-o-arrow-path')
            ->color(Color::Slate)
            ->tooltip('Синхронизировать аккаунт amoCRM')
            ->disabled(fn() => !$account->active);
    }

    public static function amoCRMAuthButton(Account $account): Action
    {
        return Action::make('amocrmAuth')
            ->action('amocrmAuth')
            ->color(fn() => $account->active ? Color::Red : Color::Green)
            ->icon(fn() => $account->active ? 'heroicon-o-power' : 'heroicon-o-key')
            ->label(fn() => $account->active ? 'Отключить amoCRM' : 'Подключить amoCRM')
            ->tooltip(fn() => $account->active ? 'Отключить платформу от аккаунта amoCRM' : 'Подключить платформу к amoCRM');
    }
}

<?php

namespace App\Helpers\Actions;

use App\Models\App;
use App\Models\Core\Account;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
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

                    // тут спокойно управляем кнопкой, приложение доступно юзеру
                    if ($app->status == App::STATE_ACTIVE || $app->status == App::STATE_CREATED) {

                        if ($record->active) {

                            $record->active = false;

                            Notification::make()
                                ->title('Интеграция выключена')
                                ->danger()
                                ->send();
                        }

                        if (!$record->active) {

                            $record->active = true;

                            Notification::make()
                                ->title('Интеграция включена')
                                ->success()
                                ->send();
                        }
                    }

                    // тут недоступно
                    if ($app->status == App::STATE_EXPIRES || $app->status == App::STATE_INACTIVE) {

                        if ($record->active) {

                            $record->active = false;

                            Notification::make()
                                ->title('Интеграция выключена')
                                ->danger()
                                ->send();
                        }

                        if (!$record->active) {

                            Notification::make()
                                ->title('Интеграция не оплачена')
                                ->warning()
                                ->body('Для оплаты обратитесь в чат ниже')
                                ->send();
                        }
                    }

                    $record->save();
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

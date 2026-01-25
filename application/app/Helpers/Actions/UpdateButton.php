<?php

namespace App\Helpers\Actions;

use App\Models\App;
use App\Models\Core\Account;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Colors\Color;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

abstract class UpdateButton
{
    public static function getNotification(App $app)
    {
        return match ($app->status) {
            App::STATE_CREATED => Notification::make()
                ->title('Интеграция включена???')
                ->success()
                ->send(),
            App::STATE_INACTIVE => Notification::make()
                ->title('Интеграция выключена')
                ->danger()
                ->send(),
            App::STATE_ACTIVE => Notification::make()
                ->title('Интеграция включена')
                ->success()
                ->send(),
            App::STATE_EXPIRES =>  Notification::make()
                ->title('Для активации оплатите виджет')
                ->warning()
                ->send(),
        };
    }

    /*
     * кнопка включить/выключить
     *
     * record = setting
     */
    public static function activeUpdate(Model $record): Action
    {
        $app = $record->app;

        //амо подключено
        if (Auth::user()->account->active) {

            //если статус приложения активен
            if ($app->status == App::STATE_ACTIVE) {

                if (is_null($record->install_at)) {

                    $app->installed_at = Carbon::now();

                    $app->expires_tariff_at = Carbon::now()->addDays(14);
                    $app->save();
                }

                //активен виджет - кнопка Выключить
                return Action::make('active')
                    ->color(Color::Red)
                    ->action(function () use ($app) {

                        $app->status = App::STATE_INACTIVE;
                        $app->save();

                        static::getNotification($app);
                    })
                    ->label('Выключить');

            } else {
//                dump('else');
                //не устанавливался
                if ($app->status == App::STATE_CREATED) {

                    //кнопка Включить
                    return Action::make('active')
                        ->color(Color::Green)
                        ->action(function () use ($app) {

                            $app->status = App::STATE_ACTIVE;
                            $app->save();

                            $setting = $app->getSettingModel();

                            $setting->active = true;
                            $setting->save();

                            static::getNotification($app);
                        })
                        ->label('Включить');

                    //выключена
                } elseif ($app->status == App::STATE_INACTIVE) {

                    //если еще срок не вышел
                    if (Carbon::now() > $app->expires_tariff_at) {

                        //кнопка Включить
                        return Action::make('active')
                            ->color(Color::Green)
                            ->action(function () use ($app) {

                                $app->status = App::STATE_ACTIVE;
                                $app->save();

                                $setting = $app->getSettingModel();

                                $setting->active = true;
                                $setting->save();

                                static::getNotification($app);
                            })
                            ->label('Включить');

                    } else {
                        //срок вышел
                        //кнопка включить (но неактивная)
                        return Action::make('active')
                            ->color(Color::Green)
                            ->label('Включить')
                            ->action(function () use ($app) {

                                static::getNotification($app);
                            })
                            ->disabled()
                            ->tooltip('Для активации оплатите виджет');
                    }

                    //если период вышел
                } elseif ($app->status == App::STATE_EXPIRES) {

                    //кнопка включить (но неактивная)
                    return Action::make('active')
                        ->color(Color::Green)
                        ->label('Включить')
                        ->action(function () use ($app) {

                            static::getNotification($app);
                        })
                        ->disabled()
                        ->tooltip('Для активации оплатите виджет');
                }
            }
        } else {
            //не подключена амо
            return Action::make('active')
                ->color(Color::Green)
                ->label('Включить')
                ->action(function () use ($app) {

                    static::getNotification($app);
                })
                ->disabled()
                ->tooltip('Для активации подключите amoCRM');
        }
    }

    //кнопка для синхронизации с амо
    public static function amoCRMSyncButton(Account $account, ?\Closure $callback = null): Action
    {
        if ($account->active)

            return Action::make('amocrmSync')
                ->action($callback ?? fn () => null)
                ->label('amoCRM')
                ->icon('heroicon-o-arrow-path')
                ->color(Color::Slate)
                ->tooltip('Синхронизировать аккаунт amoCRM')
                ->disabled(fn() => !$account->active);

        else
            return static::amoCRMAuthButton($account);

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

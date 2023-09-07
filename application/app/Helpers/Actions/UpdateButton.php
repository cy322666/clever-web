<?php

namespace App\Helpers\Actions;

use Filament\Pages\Actions\Action;
use Filament\Support\Colors\Color;
use Illuminate\Database\Eloquent\Model;

abstract class UpdateButton
{
    /*
     * record = setting
     */
    public static function getAction(Model $record): Action
    {
        return Action::make('activeUpdate')
            ->action(
                function (Model $record) {

                    $app = $record->app;

                    $record->active = !$record->active;
                    $record->save();

                    $app->setStatusWithActive($record->refresh());

                    $app->sendNotificationStatus();
            })
            ->color(fn() => $record->active ? Color::Red : Color::Green)
            ->label(fn() => $record->active ? 'Выключить' : 'Включить');
    }
}

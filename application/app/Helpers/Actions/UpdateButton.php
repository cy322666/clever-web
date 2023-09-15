<?php

namespace App\Helpers\Actions;

use App\Models\App;
use Filament\Pages\Actions\Action;
use Filament\Support\Colors\Color;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

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

                    $record->active = $app->status != App::STATE_EXPIRES ? !$record->active : App::STATE_EXPIRES;
                    $record->save();

                    $app->setStatusWithActive($record->refresh(), $app);

                    $app->sendNotificationStatus();
            })
            ->color(fn() => $record->active ? Color::Red : Color::Green)
            ->label(fn() => $record->active ? 'Выключить' : 'Включить');
    }
}

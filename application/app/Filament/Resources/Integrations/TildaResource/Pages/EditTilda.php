<?php

namespace App\Filament\Resources\Integrations\TildaResource\Pages;

use App\Filament\Resources\Integrations\Bizon\WebinarResource;
use App\Filament\Resources\Integrations\TildaResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Colors\Color;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

class EditTilda extends EditRecord
{
    protected static string $resource = TildaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('activeUpdate')
                ->action(function () {
                    $this->record->active = !$this->record->active;
                    $this->record->save();

                    if ($this->record->active)

                        Notification::make()
                            ->title('Интеграция включена')
                            ->success()
                            ->send();
                    else
                        Notification::make()
                            ->title('Интеграция выключена')
                            ->danger()
                            ->send();
                })
                ->color(fn() => $this->record->active ? Color::Red : Color::Green)
                ->label(fn() => $this->record->active ? 'Выключить' : 'Включить'),

            Actions\Action::make('instruction')
                ->label('Инструкция'),

            Actions\Action::make('list')
                ->label('История')
                ->url(WebinarResource::getUrl())
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if ($data['settings']) {

            $data['settings'] = json_decode($data['settings'], true);

            for($i = 0; count($data['settings']) !== $i; $i++) {

                $data['settings'][$i]['link'] = \route('tilda.hook', [
                    'user' => Auth::user()->uuid,
                    'site' => $i,
                ]);

                $body = json_decode($data['bodies'], true)[$i] ?? [];

                $data['settings'][$i]['body'] = json_encode($body, JSON_UNESCAPED_UNICODE);
            }
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $data;
    }
}

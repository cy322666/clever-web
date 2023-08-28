<?php

namespace App\Filament\Resources\Integrations\BizonResource\Pages;

use App\Filament\Resources\Integrations\BizonResource;
use App\Models\Integrations\Alfa\Setting;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Colors\Color;

class EditBizon extends EditRecord
{
    protected static string $resource = BizonResource::class;

    protected function getActions(): array
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
        ];
    }

    public function activeUpdate()
    {

    }

    public function mutateFormDataBeforeFill(array $data): array
    {
        $data['link'] = $this->record->getLink();

        return parent::mutateFormDataBeforeFill($data);
    }
}

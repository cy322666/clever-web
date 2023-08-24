<?php

namespace App\Filament\Resources\Integrations\BizonResource\Pages;

use App\Filament\Resources\Integrations\BizonResource;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Colors\Color;

class EditBizon extends EditRecord
{
    protected static string $resource = BizonResource::class;

    protected function getActions(): array
    {
        return [
            Actions\Action::make('instruction')
                ->label('Инструкция')
//                ->action('amocrmAuth'),
        ];
    }

    public function mutateFormDataBeforeFill(array $data): array
    {
        $data['link'] = $this->record->getLink();

        return parent::mutateFormDataBeforeFill($data);
    }
}

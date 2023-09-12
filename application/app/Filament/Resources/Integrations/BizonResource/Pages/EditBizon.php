<?php

namespace App\Filament\Resources\Integrations\BizonResource\Pages;

use App\Filament\Resources\Integrations\Bizon\WebinarResource;
use App\Filament\Resources\Integrations\BizonResource;
use App\Helpers\Actions\UpdateButton;
use Filament\Pages\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBizon extends EditRecord
{
    protected static string $resource = BizonResource::class;

    protected function getActions(): array
    {
        return [
            UpdateButton::getAction($this->record),

            Actions\Action::make('instruction')
                ->label('Инструкция')
                ->url('https://youtu.be/5-0YZJTE6ww?si=kxKeglVIT--DqcFF')
                ->openUrlInNewTab(),

            Actions\Action::make('list')
                ->label('История')
                ->url(WebinarResource::getUrl())
        ];
    }

    public function mutateFormDataBeforeFill(array $data): array
    {
        $data['link_webinar'] = $this->record->getWebinarLink();
        $data['link_form']    = $this->record->getFormLink();

        return parent::mutateFormDataBeforeFill($data);
    }
}

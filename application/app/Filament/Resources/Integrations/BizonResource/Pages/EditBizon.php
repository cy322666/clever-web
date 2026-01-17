<?php

namespace App\Filament\Resources\Integrations\BizonResource\Pages;

use App\Filament\Resources\Integrations\Bizon\WebinarResource;
use App\Filament\Resources\Integrations\BizonResource;
use App\Helpers\Actions\UpdateButton;
use App\Helpers\Traits\SyncAmoCRMPage;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditBizon extends EditRecord
{
    use SyncAmoCRMPage;

    protected static string $resource = BizonResource::class;

    protected function getActions(): array
    {
        return [
            UpdateButton::activeUpdate($this->record),

            UpdateButton::amoCRMSyncButton(
                Auth::user()->account,
                fn () => $this->amocrmUpdate(),
            ),

            Action::make('list')
                ->label('История')
                ->icon('heroicon-o-list-bullet')
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

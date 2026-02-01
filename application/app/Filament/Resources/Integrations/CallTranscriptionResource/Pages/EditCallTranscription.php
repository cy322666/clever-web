<?php

namespace App\Filament\Resources\Integrations\CallTranscriptionResource\Pages;

use App\Filament\Resources\Integrations\CallTranscriptionResource;
use App\Helpers\Actions\UpdateButton;
use App\Helpers\Traits\SyncAmoCRMPage;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditCallTranscription extends EditRecord
{
    use SyncAmoCRMPage;

    protected static string $resource = CallTranscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UpdateButton::activeUpdate($this->record),

            UpdateButton::amoCRMSyncButton(
                Auth::user()->account,
                fn () => $this->amocrmUpdate(),
            ),

            Actions\Action::make('history')
                ->label('История')
                ->icon('heroicon-o-list-bullet')
                ->url(CallTranscriptionResource::getUrl('transactions')),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if ($data['settings']) {
            $data['settings'] = json_decode($data['settings'], true);

            for ($i = 0; count($data['settings']) !== $i; $i++) {
                $settingCode = $data['settings'][$i]['code'] ?? $i;

                $data['settings'][$i]['link'] = route('amocrm.call-transcription', [
                    'user' => Auth::user()->uuid,
                    'setting' => $settingCode,
                ]);
            }
        }

        return $data;
    }
}

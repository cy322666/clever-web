<?php

namespace App\Filament\Resources\Integrations\TildaResource\Pages;

use App\Filament\Resources\Integrations\Bizon\WebinarResource;
use App\Filament\Resources\Integrations\Tilda\FormResource;
use App\Filament\Resources\Integrations\TildaResource;
use App\Helpers\Actions\UpdateButton;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditTilda extends EditRecord
{
    protected static string $resource = TildaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UpdateButton::getAction($this->record),

            Actions\Action::make('instruction')
                ->label('Инструкция')
                ->url('https://youtu.be/b5aPWhK2oc8?si=nSGpU-XRSlTRScNQ')
                ->openUrlInNewTab(),

            Actions\Action::make('list')
                ->label('История')
                ->url(FormResource::getUrl())
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

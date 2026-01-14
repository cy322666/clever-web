<?php

namespace App\Filament\Resources\Integrations\YClients\Pages;

use App\Filament\Resources\Integrations\YClients\YClientsResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditYClients extends EditRecord
{
    protected static string $resource = YClientsResource::class;

    protected function getHeaderActions(): array
    {
        return [
//            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
//        if ($data['settings']) {
//            $data['settings'] = json_decode($data['settings'], true);

//            for($i = 0; count($data['settings']) !== $i; $i++) {

                $data['fields_contact'] = json_decode($data['fields_contact'], true);
                $data['fields_lead'] = json_decode($data['fields_lead'], true);

                $data['link'] = \route('yclients.hook', [
                    'user' => Auth::user()->uuid,
                ]);

//                $body = json_decode($data['bodies'], true)[$i] ?? [];

//                $data['settings'][$i]['body'] = json_encode($body, JSON_UNESCAPED_UNICODE);
//            }
//        }

        return $data;
    }
}

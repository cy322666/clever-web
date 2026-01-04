<?php

namespace App\Filament\Resources\Integrations\AnalyticResource\Pages;

use App\Filament\Resources\Integrations\AnalyticResource;
use App\Helpers\Traits\SyncAmoCRMPage;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditAnalytic extends EditRecord
{
    use SyncAmoCRMPage;

    protected static string $resource = AnalyticResource::class;

    protected function getHeaderActions(): array
    {
        return [
//            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        //forms
        if ($data['settings']) {
            $data['settings'] = json_decode($data['settings'], true);

            for ($i = 0; count($data['settings']) !== $i; $i++) {
//                $data['settings'][$i]['link_form'] = \route('getcourse.form', [
//                        'user' => Auth::user()->uuid,
//                        'form' => $i,
//                    ]) . '/?phone={object.phone}&name={object.first_name}&email={object.email}&utm_source={object.create_session.utm_source}&utm_medium={create_session.utm_medium}&utm_campaign={create_session.utm_campaign}&utm_content={create_session.utm_content}&utm_term={create_session.utm_term}';

                $body = !empty($data['bodies']) ? json_decode($data['bodies'], true)[$i] : [];

                $data['settings'][$i]['body'] = json_encode($body, JSON_UNESCAPED_UNICODE);
            }
        }

        return $data;
    }
}

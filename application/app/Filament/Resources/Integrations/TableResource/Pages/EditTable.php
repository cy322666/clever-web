<?php

namespace App\Filament\Resources\Integrations\TableResource\Pages;

use App\Console\Commands\Table\ParseExcel;
use App\Filament\Resources\Integrations\TableResource;
use App\Models\Integrations\Table\User;
use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EditTable extends EditRecord
{
    protected static string $resource = TableResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if ($data['settings']) {
            $data['settings'] = json_decode($data['settings'], true);

//            for($i = 0; count($data['settings']) !== $i; $i++) {

//                $data['settings'][$i]['link'] = \route('doc.hook', [
//                    'user' => Auth::user()->uuid,
//                    'doc'  => $i,
//                ]);

//                $body = json_decode($data['bodies'], true)[$i] ?? [];

//                $data['settings'][$i]['body'] = json_encode($body, JSON_UNESCAPED_UNICODE);
//            }
        }

        return parent::mutateFormDataBeforeSave($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($data['settings']) {

            for($i = 0; count($data['settings']) !== $i; $i++, $filename = null) {

                if (!isset($data['settings'][$i]['format_format'])) {

                    $filename = str_replace('table/raw//', '', $data['settings'][$i]['base_raw']);

                     $result = Artisan::call('app:parse-excel', [
                        'file_path' => $data['settings'][$i]['base_raw'],
                        'user_id'   => Auth::id(),
                        'setting_id' => $i,
                        'filename' => $filename
                    ]);

                     if (!$result) {

                        $data['settings'][$i]['base_format'] = storage_path('app/public/').'table/format/'.$filename;
                     }
                }
            }
        }

        return $data;
    }
}

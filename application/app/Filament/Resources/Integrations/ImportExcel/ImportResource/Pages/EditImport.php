<?php

namespace App\Filament\Resources\Integrations\ImportExcel\ImportResource\Pages;

use App\Filament\Resources\Integrations\ImportExcel\ImportResource;
use App\Helpers\Actions\UpdateButton;
use App\Helpers\Traits\SyncAmoCRMPage;
use App\Models\Integrations\ImportExcel\ImportRecord;
use App\Models\Integrations\ImportExcel\ImportSetting;
use App\Services\ImportExcel\ExcelImport;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Maatwebsite\Excel\Facades\Excel;

class EditImport extends EditRecord
{
    use SyncAmoCRMPage;

    protected static string $resource = ImportResource::class;

    public function import(): void
    {
        $setting = ImportSetting::query()
            ->where('user_id', Auth::id())
            ->first();

        if (!$setting || !$setting->active) {
            Notification::make()
                ->title('Ошибка')
                ->body('Настройки не активны или не найдены')
                ->danger()
                ->send();
            return;
        }

        if (!$setting->file_path) {
            Notification::make()
                ->title('Ошибка')
                ->body('Выберите файл для импорта')
                ->danger()
                ->send();
            return;
        }

        try {
            // Обработка файла: может быть строка (уже сохранённый) или TemporaryUploadedFile
            // if (is_array($fileData)) {
            //     $fileData = reset($fileData); // берём первый элемент массива
            // }

//            if ($fileData instanceof TemporaryUploadedFile) {
//                // Файл ещё временный - сохраняем его
//                $originalName = $fileData->getClientOriginalName();
//                $storedPath = $fileData->store('imports', 'local');
//                $filePath = Storage::disk('local')->path($storedPath);
//                $filename = $originalName;
//            } elseif (is_string($fileData)) {
//                // Уже сохранённый путь
            // $filePath = Storage::disk('local')->path($fileData);
//                $filename = explode('/', $setting->file_path)[0];//imports/01KGDH1YCSMM987E00KWWTQSEZ.xlsx
            // $storedPath = $fileData;
//            } else {
//                throw new \RuntimeException('Неверный формат файла');
//            }

//            if (!file_exists($filePath)) {
//                throw new \RuntimeException('Файл не найден: ' . $filePath);
//            }

            // Создаём запись импорта
//            $importRecord = ImportRecord::query()
//                ->create([
//                    'import_id' => $setting->id,
//                    'user_id' => Auth::id(),
//                    'filename' => $setting->file_path,
//                    'file_path' => $setting->file_path,
//                    'status' => ImportRecord::STATUS_PROCESSING,
//                    'total_rows' => 0,
//                    'processed_rows' => 0,
//                    'success_rows' => 0,
//                    'error_rows' => 0,
//                ]);

            Excel::import(new ExcelImport($setting), Storage::disk('exports')->path($setting->file_path));

            Notification::make()
                ->title('Импорт запущен')
                ->body('Данные импортируются в фоновом режиме. Проверьте результаты через некоторое время.')
                ->success()
                ->send();

            $this->redirect(ImportResource::getUrl('list'));

        } catch (\Exception $e) {
//            if (isset($importRecord))
//                $importRecord->update([
//                    'status' => ImportRecord::STATUS_FAILED,
//                    'error_message' => $e->getMessage(),
//                ]);

            Notification::make()
                ->title('Ошибка импорта')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    protected function getActions(): array
    {
        return [
            UpdateButton::activeUpdate($this->record),

            UpdateButton::amoCRMSyncButton(
                Auth::user()->account,
                fn() => $this->amocrmUpdate(),
            ),

            Action::make('list')
                ->label('История импорта')
                ->icon('heroicon-o-list-bullet')
                ->url(ImportResource::getUrl('list')),

            Action::make('import')
                ->label('Начать импорт')
                ->action(fn() => $this->import())
                ->color('primary'),
        ];
    }

//    protected function mutateFormDataBeforeFill(array $data): array
//    {
////        if (isset($data['fields_mapping']) && is_string($data['fields_mapping'])) {
////            $data['fields_mapping'] = json_decode($data['fields_mapping'], true) ?? [];
////        }
//
//        $data['fields_leads'] = json_decode($data['fields_leads'], true) ?? [];
//        $data['fields_contacts'] = json_decode($data['fields_contacts'], true) ?? [];
//        $data['fields_companies'] = json_decode($data['fields_companies'], true) ?? [];
//
//        return $data;
//    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
//        if (isset($data['fields_mapping']) && is_array($data['fields_mapping'])) {
//            $data['fields_mapping'] = json_encode($data['fields_mapping'], JSON_UNESCAPED_UNICODE);
//        }
//dd($data);
//        $data['fields_leads'] = json_encode($data['fields_leads'], JSON_UNESCAPED_UNICODE);
//        $data['fields_contacts'] = json_encode($data['fields_contacts'], JSON_UNESCAPED_UNICODE);
//        $data['fields_companies'] = json_encode($data['fields_companies'], JSON_UNESCAPED_UNICODE);

        return $data;
    }
}

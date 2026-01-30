<?php

namespace App\Filament\Resources\Integrations\ImportExcel\ImportResource\Pages;

use App\Filament\Resources\Integrations\ImportExcel\ImportResource;
use App\Helpers\Actions\UpdateButton;
use App\Helpers\Traits\SyncAmoCRMPage;
use App\Imports\amoCRM\ExcelImport;
use App\Models\Integrations\ImportExcel\ImportRecord;
use App\Models\Integrations\ImportExcel\ImportSetting;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

class EditImport extends EditRecord
{
    use SyncAmoCRMPage;

    protected static string $resource = ImportResource::class;

    public function import(): void
    {
        $data = $this->form->getState();
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

        if (!$data['file']) {
            Notification::make()
                ->title('Ошибка')
                ->body('Выберите файл для импорта')
                ->danger()
                ->send();
            return;
        }

        try {
            $filePath = storage_path('app/' . $data['file']);
            $filename = basename($data['file']);

            // Создаём запись импорта
            $importRecord = ImportRecord::create([
                'import_id' => $setting->id,
                'user_id' => Auth::id(),
                'filename' => $filename,
                'status' => ImportRecord::STATUS_PROCESSING,
                'total_rows' => 0,
                'processed_rows' => 0,
                'success_rows' => 0,
                'error_rows' => 0,
            ]);

            Excel::import(new ExcelImport($setting, $importRecord), $filePath);

            Notification::make()
                ->title('Импорт запущен')
                ->body('Данные импортируются в фоновом режиме. Проверьте результаты через некоторое время.')
                ->success()
                ->send();

            $this->redirect(ImportResource::getUrl('list'));
        } catch (\Exception $e) {
            if (isset($importRecord)) {
                $importRecord->update([
                    'status' => ImportRecord::STATUS_FAILED,
                    'error_message' => $e->getMessage(),
                ]);
            }

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

//            Action::make('import')
//                ->label('Импорт из Excel')
//                ->icon('heroicon-o-arrow-down-tray')
//                ->url(ImportResource::getUrl('import')),

            Action::make('list')
                ->label('История импорта')
                ->icon('heroicon-o-list-bullet')
                ->url(ImportResource::getUrl('list')),

            Action::make('import')
                ->label('Начать импорт')
                ->submit('import')
                ->color('primary'),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (isset($data['fields_mapping']) && is_string($data['fields_mapping'])) {
            $data['fields_mapping'] = json_decode($data['fields_mapping'], true) ?? [];
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (isset($data['fields_mapping']) && is_array($data['fields_mapping'])) {
            $data['fields_mapping'] = json_encode($data['fields_mapping'], JSON_UNESCAPED_UNICODE);
        }

        return $data;
    }
}

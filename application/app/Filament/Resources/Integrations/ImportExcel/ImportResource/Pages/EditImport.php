<?php

namespace App\Filament\Resources\Integrations\ImportExcel\ImportResource\Pages;

use App\Filament\Resources\Integrations\ImportExcel\ImportResource;
use App\Helpers\Actions\UpdateButton;
use App\Helpers\Traits\SyncAmoCRMPage;
use App\Jobs\ImportExcel\ParseImportFile;
use App\Models\Integrations\ImportExcel\ImportSetting;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

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
            ParseImportFile::dispatch($setting->id);

            Notification::make()
                ->title('Импорт запущен')
                ->body('Файл поставлен в очередь на обработку. Обновите историю импорта через несколько секунд.')
                ->success()
                ->send();

            $this->redirect(ImportResource::getUrl('list'));
        } catch (\Exception $e) {
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
                $this->record->amoAccount(true),
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

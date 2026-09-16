<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use App\Services\Workflows\WorkflowFolders;
use App\Services\Workflows\WorkflowTransfer;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;

trait HasWorkflowImport
{
    public function importWorkflowAction(): Action
    {
        return Action::make('importWorkflow')->label('Импорт')->icon('heroicon-o-arrow-up-tray')->iconButton()->tooltip('Импорт')->color('gray')
            ->modalHeading('Импорт потока')->modalWidth('md')
            ->modalDescription('Создаст новый выключенный поток. Проверьте код, URL и действия перед запуском. Подключения и заголовки HTTP нужно настроить заново.')
            ->schema([
                FileUpload::make('file')->label('JSON-файл')->required()->maxSize(2048)->storeFiles(false)->acceptedFileTypes(['application/json','text/plain']),
                Select::make('group_name')->label('Папка')->options(fn () => WorkflowFolders::options())->placeholder('Без папки'),
            ])->action(function (array $data): void {
                try {
                    $workflow = WorkflowTransfer::import($data['file']->get(), $data['group_name'] ?? null);
                } catch (\JsonException|\InvalidArgumentException $error) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['mountedActions.0.data.file'=>$error->getMessage()]);
                }
                $this->redirect(WorkflowResource::getUrl('edit',['record'=>$workflow]));
            });
    }
}

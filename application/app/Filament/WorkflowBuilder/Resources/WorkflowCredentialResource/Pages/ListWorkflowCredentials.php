<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowCredentialResource\Pages;

use App\Filament\WorkflowBuilder\Resources\WorkflowCredentialResource;
use Filament\Resources\Pages\ListRecords;

class ListWorkflowCredentials extends ListRecords
{
    protected static string $resource = WorkflowCredentialResource::class;

    protected static ?string $title = 'Подключения сервисов';

    protected ?string $subheading = 'Секреты намеренно скрыты. Для поддержки доступны владелец, сервис и имя подключения.';

    protected function getHeaderActions(): array
    {
        return [];
    }
}

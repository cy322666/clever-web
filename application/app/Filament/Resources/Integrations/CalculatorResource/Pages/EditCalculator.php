<?php

namespace App\Filament\Resources\Integrations\CalculatorResource\Pages;

use App\Filament\Resources\Integrations\CalculatorResource;
use App\Helpers\Actions\UpdateButton;
use App\Helpers\Traits\SyncAmoCRMPage;
use App\Workflows\Actions\WorkflowTriggerConditionVariableCatalog;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCalculator extends EditRecord
{
    use SyncAmoCRMPage;

    protected static string $resource = CalculatorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UpdateButton::activeUpdate($this->record),

            UpdateButton::amoCRMSyncButton(
                $this->record->amoAccount(true),
                fn() => $this->amocrmUpdate(),
            ),

            Actions\Action::make('variables')
                ->label('Переменные')
                ->icon('heroicon-o-variable')
                ->color('gray')
                ->modalHeading('Справочник переменных и ID')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Закрыть')
                ->modalWidth('5xl')
                ->modalContent(fn() => view('filament.workflow-builder.mask-reference', [
                    'groups' => WorkflowTriggerConditionVariableCatalog::groupedOptions(false),
                    'systemIdGroups' => WorkflowTriggerConditionVariableCatalog::systemIdGroups(),
                ])),

            Actions\Action::make('history')
                ->label('История')
                ->icon('heroicon-o-list-bullet')
                ->url(CalculatorResource::getUrl('transactions')),
        ];
    }
}

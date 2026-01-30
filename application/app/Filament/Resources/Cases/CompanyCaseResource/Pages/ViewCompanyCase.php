<?php

namespace App\Filament\Resources\Cases\CompanyCaseResource\Pages;

use App\Filament\Resources\Cases\CompanyCaseResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Colors\Color;

class ViewCompanyCase extends ViewRecord
{
    protected static string $resource = CompanyCaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('edit')
                ->label('Редактировать')
                ->url(fn () => CompanyCaseResource::getUrl('edit', ['record' => $this->record]))
                ->color(Color::Gray),
            Action::make('public')
                ->label('Посмотреть')
                ->url(fn () => url('/catalog/cases/' . $this->record->slug))
                ->openUrlInNewTab()
                ->color(Color::Gray),
        ];
    }
}

<?php

namespace App\Filament\Resources\Cases\CompanyCaseResource\Pages;

use App\Filament\Resources\Cases\CompanyCaseResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Colors\Color;

class EditCompanyCase extends EditRecord
{
    protected static string $resource = CompanyCaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('public')
                ->label('Посмотреть')
                ->url(fn () => url('/catalog/cases/' . $this->record->slug))
                ->openUrlInNewTab()
                ->color(Color::Gray),
        ];
    }
}

<?php

namespace App\Filament\Resources\Cases\CompanyCaseResource\Pages;

use App\Filament\Resources\Cases\CompanyCaseResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Colors\Color;

class ListCompanyCases extends ListRecords
{
    protected static string $resource = CompanyCaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('catalog')
                ->label('В каталог')
                ->url(env('APP_URL') . '/catalog/cases')
                ->openUrlInNewTab()
                ->color(Color::Gray),
            CreateAction::make(),
        ];
    }
}

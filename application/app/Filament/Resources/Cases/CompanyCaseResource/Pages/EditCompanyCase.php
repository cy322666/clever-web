<?php

namespace App\Filament\Resources\Cases\CompanyCaseResource\Pages;

use App\Filament\Resources\Cases\CompanyCaseResource;
use Filament\Resources\Pages\EditRecord;

class EditCompanyCase extends EditRecord
{
    protected static string $resource = CompanyCaseResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}

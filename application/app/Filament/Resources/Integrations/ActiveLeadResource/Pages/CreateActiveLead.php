<?php

namespace App\Filament\Resources\ActiveLeadResource\Pages;

use App\Filament\Resources\ActiveLeadResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateActiveLead extends CreateRecord
{
    protected static string $resource = ActiveLeadResource::class;
}

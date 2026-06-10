<?php

namespace App\Filament\Resources\Core\AuthenticationLogResource\Pages;

use App\Filament\Resources\Core\AuthenticationLogResource;
use Filament\Resources\Pages\ListRecords;

class ListAuthenticationLogs extends ListRecords
{
    protected static string $resource = AuthenticationLogResource::class;
}

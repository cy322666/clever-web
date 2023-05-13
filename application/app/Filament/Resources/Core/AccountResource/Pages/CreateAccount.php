<?php

namespace App\Filament\Resources\Core\AccountResource\Pages;

use App\Filament\Resources\Core\AccountResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateAccount extends CreateRecord
{
    protected static string $resource = AccountResource::class;
}

<?php

namespace App\Filament\Resources\Core\UserResource\Pages;

use App\Filament\Resources\Core\UserResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;
}

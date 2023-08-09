<?php

namespace App\Filament\Resources\Core\UserResource\Pages;

use App\Filament\Resources\Core\UserResource;
use App\Models\amoCRM\Staff;
use App\Services\amoCRM\Client;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

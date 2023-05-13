<?php

namespace App\Filament\Resources\BizonResource\Pages;

use App\Filament\Resources\BizonResource;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Actions;
use Filament\Resources\Form;
use Filament\Resources\Pages\EditRecord;

class EditBizon extends EditRecord
{
    protected static string $resource = BizonResource::class;

    protected function getActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

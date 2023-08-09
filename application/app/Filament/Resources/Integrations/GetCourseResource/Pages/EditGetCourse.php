<?php

namespace App\Filament\Resources\Integrations\GetCourseResource\Pages;

use App\Filament\Resources\Integrations\GetCourseResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\EditRecord;

class EditGetCourse extends EditRecord
{
    protected static string $resource = GetCourseResource::class;

    protected function getActions(): array
    {
        return [
//            Actions\DeleteAction::make(),
        ];
    }
}

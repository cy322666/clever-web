<?php

namespace App\Filament\Resources\Integrations\GetCourseResource\Pages;

use App\Filament\Resources\Integrations\GetCourseResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ListRecords;

class ListGetCourses extends ListRecords
{
    protected static string $resource = GetCourseResource::class;

    protected function getActions(): array
    {
        return [
//            Actions\CreateAction::make(),
        ];
    }
}

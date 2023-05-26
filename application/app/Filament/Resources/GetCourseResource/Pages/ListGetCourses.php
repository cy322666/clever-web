<?php

namespace App\Filament\Resources\GetCourseResource\Pages;

use App\Filament\Resources\GetCourseResource;
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

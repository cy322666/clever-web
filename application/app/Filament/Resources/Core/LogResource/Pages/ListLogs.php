<?php

namespace App\Filament\Resources\Core\LogResource\Pages;

use App\Filament\Resources\Core\LogResource;
use App\Models\Log;
use Filament\Actions;
use Filament\Pages\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Colors\Color;
use Illuminate\Database\Eloquent\Model;

class ListLogs extends ListRecords
{
    protected static string $resource = LogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('truncate')
                ->label('Отчистить')
                ->action(fn() => Log::query()->truncate())
                ->color(Color::Red)
        ];
    }
}

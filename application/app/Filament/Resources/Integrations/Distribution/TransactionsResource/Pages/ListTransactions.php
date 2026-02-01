<?php

namespace App\Filament\Resources\Integrations\Distribution\TransactionsResource\Pages;

use App\Filament\Resources\Integrations\Distribution\ScheduleResource;
use App\Filament\Resources\Integrations\Distribution\TransactionsResource;
use App\Filament\Resources\Integrations\DistributionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ListTransactions extends ListRecords
{
    protected static string $resource = TransactionsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('logs')
                ->label('Шаблоны')
                ->url(DistributionResource::getUrl('edit', ['record' => Auth::user()->distribution_settings->id])),

            Actions\Action::make('schedule')
                ->label('График')
                ->url(ScheduleResource::getUrl())
        ];
    }

    public function table(Table $table): Table
    {
        return TransactionsResource::table($table);
    }
}

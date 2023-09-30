<?php

namespace App\Filament\Resources\Integrations\Alfa\TransactionResource\Pages;

use App\Filament\Resources\Integrations\Alfa\TransactionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTransactions extends ListRecords
{
    protected static string $resource = TransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}

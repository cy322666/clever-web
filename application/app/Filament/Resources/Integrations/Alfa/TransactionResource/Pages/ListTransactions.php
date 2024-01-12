<?php

namespace App\Filament\Resources\Integrations\Alfa\TransactionResource\Pages;

use App\Filament\Resources\Integrations\Alfa\TransactionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListTransactions extends ListRecords
{
    protected static string $resource = TransactionResource::class;

    protected function getTableQuery(): ?Builder
    {
        $query = parent::getTableQuery();

        if (!Auth::user()->is_root) {

            $query->where('user_id', Auth::id());
        }

        return $query;
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}

<?php

namespace App\Filament\Resources\Billing\InvoiceRequestResource\Pages;

use App\Filament\Resources\Billing\InvoiceRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListInvoiceRequests extends ListRecords
{
    protected static string $resource = InvoiceRequestResource::class;
}

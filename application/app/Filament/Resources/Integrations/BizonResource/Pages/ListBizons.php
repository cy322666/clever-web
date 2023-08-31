<?php

namespace App\Filament\Resources\Integrations\BizonResource\Pages;

use App\Filament\Resources\Integrations\BizonResource;
use App\Models\Integrations\Bizon\Webinar;
use Filament\Pages\Actions;
use Filament\Pages\Concerns\ExposesTableToWidgets;
use Filament\Resources\Pages\ListRecords;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListBizons extends ListRecords
{
    protected static string $resource = BizonResource::class;

    use ExposesTableToWidgets;

    protected function getFooterWidgets(): array
    {
        return [
//            BizonResource\Widgets\ViewersTable::class
        ];
    }
}

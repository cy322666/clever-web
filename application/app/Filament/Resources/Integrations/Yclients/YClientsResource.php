<?php

namespace App\Filament\Resources\Integrations\YClients;

use App\Filament\Resources\Integrations\YClients\Pages\CreateYClients;
use App\Filament\Resources\Integrations\YClients\Pages\EditYClients;
use App\Filament\Resources\Integrations\YClients\Pages\ListYClients;
use App\Filament\Resources\Integrations\YClients\Schemas\YClientsForm;
use App\Filament\Resources\Integrations\YClients\Tables\YClientsTable;
use App\Helpers\Traits\SettingResource;
use App\Helpers\Traits\TenantResource;
use App\Models\Integrations\YClients;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class YClientsResource extends Resource
{
    use TenantResource, SettingResource;

    protected static ?string $model = YClients\Setting::class;

    protected static ?string $recordTitleAttribute = 'YClients';

//    protected static ?string $slug = 'integrations/yclients';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return YClientsForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return YClientsTable::configure($table);
    }

    public static function getTransactions(): string
    {
        return YClients\Record::query()->count();
    }

    public static function clearTransactions(int $days = 7): bool
    {
        YClients\Record::query()
            ->where('created_at', '<', Carbon::now()
                ->subDays($days)
                ->format('Y-m-d')
            )->delete();

        return true;
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
//            'index' => ListYClients::route('/'),
            'list' => ListYClients::route('/list'),
            'edit' => EditYClients::route('/{record}/edit'),
        ];
    }
}

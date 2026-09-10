<?php

namespace App\Filament\Resources\Integrations\Sqns;

use App\Filament\Resources\Integrations\Sqns\Pages\EditSqns;
use App\Filament\Resources\Integrations\Sqns\Pages\ListSqnsVisits;
use App\Filament\Resources\Integrations\Sqns\Schemas\SqnsForm;
use App\Helpers\Traits\SettingResource;
use App\Helpers\Traits\TenantResource;
use App\Models\Integrations\Sqns\Setting;
use App\Models\Integrations\Sqns\Visit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SqnsResource extends Resource
{
    use SettingResource, TenantResource;

    protected static ?string $model = Setting::class;

    protected static ?string $recordTitleAttribute = 'SQNS';

    protected static ?string $slug = 'integrations/sqns';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return SqnsForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([])->filters([])->recordActions([])->toolbarActions([]);
    }

    public static function getTransactions(): string
    {
        return (string) Visit::query()->count();
    }

    public static function clearTransactions(int $days = 7): bool
    {
        Visit::query()->where('created_at', '<', now()->subDays($days))->delete();

        return true;
    }

    public static function getPages(): array
    {
        return [
            'edit' => EditSqns::route('/{record}/edit'),
            'visits' => ListSqnsVisits::route('/visits'),
        ];
    }
}

<?php

namespace App\Filament\Resources\Integrations\Vetmanager;

use App\Filament\Resources\Integrations\Vetmanager\Pages\EditVetmanager;
use App\Filament\Resources\Integrations\Vetmanager\Pages\ListVetmanagerVisits;
use App\Filament\Resources\Integrations\Vetmanager\Schemas\VetmanagerForm;
use App\Helpers\Traits\SettingResource;
use App\Helpers\Traits\TenantResource;
use App\Models\Integrations\Vetmanager\Setting;
use App\Models\Integrations\Vetmanager\Visit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class VetmanagerResource extends Resource
{
    use SettingResource, TenantResource;

    protected static ?string $model = Setting::class;

    protected static ?string $recordTitleAttribute = 'Vetmanager';

    protected static ?string $slug = 'integrations/vetmanager';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return VetmanagerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([])->filters([])->recordActions([])->toolbarActions([]);
    }

    public static function getTransactions(): string
    {
        return (string) Visit::query()->where('user_id', Auth::id())->count();
    }

    public static function clearTransactions(int $days = 7): bool
    {
        Visit::query()
            ->where('user_id', Auth::id())
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        return true;
    }

    public static function getPages(): array
    {
        return [
            'edit' => EditVetmanager::route('/{record}/edit'),
            'visits' => ListVetmanagerVisits::route('/visits'),
        ];
    }
}

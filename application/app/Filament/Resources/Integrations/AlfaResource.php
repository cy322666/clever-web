<?php

namespace App\Filament\Resources\Integrations;

use App\Filament\Resources\Integrations\AlfaResource\Pages;
use App\Helpers\Traits\SettingResource;
use App\Helpers\Traits\TenantResource;
use App\Models\amoCRM\Status;
use App\Models\Integrations\Alfa\Branch;
use App\Models\Integrations\Alfa\LeadStatus;
use App\Models\Integrations\Alfa\Setting;
use App\Models\Integrations\Alfa\Transaction;
use Carbon\Carbon;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Table;
use Livewire\Features\Placeholder;

class AlfaResource extends Resource
{
    use TenantResource, SettingResource;

    protected static ?string $model = Setting::class;

    protected static ?string $recordTitleAttribute = 'АльфаСРМ';

    protected static ?string $slug = 'settings/alfacrm';

//    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return
            $schema->schema([
                Section::make('Основное')
                   ->hiddenLabel()
                   ->schema([
                       Fieldset::make('Доступы')
                           ->schema([
                                TextInput::make('api_key')
                                    ->label('Токен')
                                    ->required(),
                                TextInput::make('domain')
                                    ->label('Домен')
                                    ->required(),
                               TextInput::make('email')
                                    ->label('Email')
                                    ->required(),
                           ]),

                       Fieldset::make('Ссылки')
                           ->schema([
                               TextInput::make('link_record')
                                   ->label('Вебхук записи')
                                   ->copyable()
                                   ->disabled(),
                               TextInput::make('link_came')
                                   ->label('Вебхук посещения')
                                   ->copyable()
                                   ->disabled(),
                               TextInput::make('link_omission')
                                   ->label('Вебхук отмены')
                                   ->copyable()
                                   ->disabled(),
                           ]),

                       Fieldset::make('Настройки amoCRM')
                           ->schema([

                               Select::make('status_record_1')
                                   ->label('Статус записи')
                                   ->options(Status::getWithoutUnsorted()->pluck('name', 'id') ?? [])
                                   ->searchable(),

                               Select::make('status_came_1')
                                   ->label('Статус пришедших')
                                   ->options(Status::getWithoutUnsorted()->pluck('name', 'id') ?? [])
                                   ->searchable(),

                               Select::make('status_omission_1')
                                   ->label('Статус отказавшихся')
//                                   ->options(Status::getWithoutUnsorted()->pluck('name', 'id'))
                                   ->options(Status::getTriggerStatuses())
                                   ->searchable(),
                           ]),

                       Fieldset::make('Настройка AlfaCRM')
                           ->schema([

                               Select::make('stage_record_1')
                                   ->label('Этап записи')
                                   ->options(LeadStatus::getWithUser()->pluck('name', 'id') ?? [])
                                   ->searchable(),

                               Select::make('stage_came_1')
                                   ->label('Этап пришедших')
                                   ->options(LeadStatus::getWithUser()->pluck('name', 'id') ?? [])
                                   ->searchable(),

                               Select::make('stage_omission_1')
                                   ->label('Этап отказавшихся')
                                   ->options(LeadStatus::getWithUser()->pluck('name', 'id') ?? [])
                                   ->searchable(),

                               Select::make('branch_id')
                                   ->label('Филиал')
                                   ->options(Branch::getWithUser()->pluck('name', 'id') ?? [])
//                                   ->required()
                                   ->searchable(),

//                               Checkbox::make('work_lead')
//                                   ->label('Работа с лидами'),
                           ]),
                   ])
                   ->columnSpan(2),

                Section::make()
                    ->schema([
                        TextEntry::make('link')
                            ->label('Инструкция')
                            ->color('primary')
    //                            ->markdown(),
                            ->fontFamily(FontFamily::Mono)
                            ->weight(FontWeight::ExtraBold),

                        TextEntry::make('price6')
                            ->money('EUR', divideBy: 100),

                        TextEntry::make('price12')
                            ->money('EUR', divideBy: 100),

                        TextEntry::make('updated_at')
                            ->label('Обновлен')
                    ])
                    ->compact()
                    ->columnSpan(1),

           ])->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([])
            ->filters([])
            ->actions([])
            ->bulkActions([])
            ->emptyStateActions([]);
    }

    public static function getTransactions(): int
    {
        return Transaction::query()->count();
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'edit'   => Pages\EditAlfa::route('/{record}/edit'),
        ];
    }

    public static function clearTransactions(int $days = 7): bool
    {
        Transaction::query()
            ->where('created_at', '<', Carbon::now()
                ->subDays($days)
                ->format('Y-m-d')
            )->delete();

        return true;
    }
}

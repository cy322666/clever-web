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
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Table;
use Livewire\Features\Placeholder;

class AlfaResource extends Resource
{
    use TenantResource, SettingResource;

    protected static ?string $model = Setting::class;

    protected static ?string $recordTitleAttribute = 'АльфаСРМ';

    protected static ?string $slug = 'integrations/alfacrm';

//    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return
            $schema->schema([
                Section::make('')
                   ->hiddenLabel()
                   ->schema([

                       Section::make()
                           ->label('')
                           ->schema([

                               TextEntry::make('Инструкция')
                                   ->bulleted()
                                   ->size(TextSize::Small)
                                   ->state(fn() => Setting::$instruction),
                           ]),

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
//                                   ->hint('Вставьте в воронку в этап записи')
                                   ->disabled(),

                               TextInput::make('link_came')
                                   ->label('Вебхук посещения')
//                                   ->hint('Вставьте в настройки АльфаСРМ')
                                   ->copyable()
                                   ->disabled(),

                               TextInput::make('link_omission')
                                   ->label('Вебхук отмены')
//                                   ->hint('Вставьте в настройки АльфаСРМ')
                                   ->copyable()
                                   ->disabled(),

                               TextInput::make('link_archive')
                                   ->label('Вебхук удаления клиента')
//                                   ->hint('Вставьте в настройки АльфаСРМ')
                                   ->copyable()
                                   ->disabled(),

                               TextInput::make('link_pay')
                                   ->label('Вебхук оплаты')
//                                   ->hint('Вставьте в настройки АльфаСРМ')
                                   ->copyable()
                                   ->disabled(),

                               TextInput::make('link_repeated')
                                   ->label('Вебхук повторного посещения')
//                                   ->hint('Вставьте в настройки АльфаСРМ')
                                   ->copyable()
                                   ->disabled(),
                           ]),

                       Fieldset::make('Настройки amoCRM')
                           ->schema([

//                               Select::make('status_record')
//                                   ->label('Статус записи')
//                                   ->options(Status::getTriggerStatuses())
//                                   ->searchable(),

                               Select::make('status_came')
                                   ->label('Статус пришедших')
                                   ->options(Status::getTriggerStatuses())
                                   ->searchable(),

                               Select::make('status_omission')
                                   ->label('Статус отказавшихся')
                                   ->options(Status::getTriggerStatuses())
                                   ->searchable(),

                               Select::make('status_archive')
                                   ->label('Статус удаленных клиентов')
                                   ->options(Status::getTriggerStatuses())
                                   ->searchable(),

                               Select::make('status_pay')
                                   ->label('Статус оплаченных')
                                   ->options(Status::getTriggerStatuses())
                                   ->searchable(),

                               Select::make('status_repeated')
                                   ->label('Статус повторных оплат')
                                   ->options(Status::getTriggerStatuses())
                                   ->searchable(),
                           ]),

                       //TODO соотношение полей

                       Fieldset::make('Настройка AlfaCRM')
                           ->schema([

                               Select::make('stage_record')
                                   ->label('Этап записи')
                                   ->options(LeadStatus::getWithUser()->pluck('name', 'id') ?? [])
                                   ->searchable(),

                               Select::make('stage_came')
                                   ->label('Этап пришедших')
                                   ->options(LeadStatus::getWithUser()->pluck('name', 'id') ?? [])
                                   ->searchable(),

                               Select::make('stage_omission')
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

                        Action::make('instruction')
                            ->label('Видео инструкция')
                            ->url('')
                            ->disabled()
                            ->openUrlInNewTab(),

                        Section::make()
                            ->schema([

                                TextEntry::make('price6')
                                    ->label('Полгода')
                                    ->money('RU', divideBy: 100)
                                    ->size(TextSize::Medium)
                                    ->state(fn($model): string => $model::$cost['6_month']),

                                TextEntry::make('price12')
                                    ->label('Год')
                                    ->money('RU', divideBy: 100)
                                    ->size(TextSize::Medium)
                                    ->state(fn($model): string => $model::$cost['12_month']),

                                TextEntry::make('bonus')
                                    ->hiddenLabel()
                                    ->size(TextSize::Small)
                                    ->state('*Бесплатно при продлении лицензий через интегратора Clever'),

                                TextEntry::make('bonus2')
                                    ->hiddenLabel()
                                    ->size(TextSize::Small)
                                    ->state('Чтобы узнать больше напишите в чат ниже'),
                            ])
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

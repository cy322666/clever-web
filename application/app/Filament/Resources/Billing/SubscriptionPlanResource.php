<?php

namespace App\Filament\Resources\Billing;

use App\Filament\Resources\Billing\SubscriptionPlanResource\Pages;
use App\Models\App;
use App\Models\Billing\SubscriptionInvoiceRequest;
use App\Models\Billing\SubscriptionPlan;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class SubscriptionPlanResource extends Resource
{
    protected static ?string $model = SubscriptionPlan::class;

    protected static ?string $navigationLabel = 'Тарифы';

    protected static ?string $modelLabel = 'Тариф';

    protected static ?string $pluralModelLabel = 'Тарифы';

    protected static string|\UnitEnum|null $navigationGroup = 'Подписки';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    public static function canViewAny(): bool
    {
        return auth()->check();
    }

    public static function canCreate(): bool
    {
        return (bool)auth()->user()?->is_root;
    }

    public static function canEdit(Model $record): bool
    {
        return (bool)auth()->user()?->is_root;
    }

    public static function canDelete(Model $record): bool
    {
        return (bool)auth()->user()?->is_root;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (!(bool)auth()->user()?->is_root) {
            $query
                ->active()
                ->whereIn('widget', static::installedWidgetNamesForCurrentUser());
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make()
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Название')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('slug')
                        ->label('Код')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),
                    Forms\Components\Select::make('widget')
                        ->label('Виджет')
                        ->options(WidgetSubscriptionResource::widgetOptions())
                        ->searchable(),
                    Forms\Components\TextInput::make('price_label')
                        ->label('Цена для отображения')
                        ->placeholder('например: 4 900 ₽ / мес.')
                        ->maxLength(255),
                    Forms\Components\TextInput::make('price_rub')
                        ->label('Цена, ₽')
                        ->numeric()
                        ->minValue(0),
                    Forms\Components\TextInput::make('period_days')
                        ->label('Период, дней')
                        ->numeric()
                        ->minValue(1),
                    Forms\Components\TagsInput::make('features')
                        ->label('Возможности')
                        ->columnSpanFull(),
                    Forms\Components\KeyValue::make('limits')
                        ->label('Лимиты')
                        ->columnSpanFull(),
                    Forms\Components\Textarea::make('description')
                        ->label('Описание')
                        ->rows(4)
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('sort_order')
                        ->label('Сортировка')
                        ->numeric()
                        ->default(100),
                    Forms\Components\Toggle::make('is_active')
                        ->label('Активен')
                        ->default(true),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Stack::make([
                    Split::make([
                        Tables\Columns\TextColumn::make('widget')
                            ->label('Виджет')
                            ->state(fn(SubscriptionPlan $record): string => static::widgetTitle($record))
                            ->weight(FontWeight::Bold)
                            ->size(TextSize::Large)
                            ->searchable()
                            ->sortable(),

                        Tables\Columns\TextColumn::make('period_label')
                            ->label('Период')
                            ->state(fn(SubscriptionPlan $record): string => static::periodLabel($record))
                            ->badge()
                            ->color('warning')
                            ->alignRight(),
                    ]),

                    Tables\Columns\TextColumn::make('price_label')
                        ->label('Цена')
                        ->state(fn(SubscriptionPlan $record): string => $record->price_label ?: 'По запросу')
                        ->weight(FontWeight::Bold)
                        ->size(TextSize::Large)
                        ->color('primary'),

                    Tables\Columns\TextColumn::make('description')
                        ->label('Описание')
                        ->state(fn(SubscriptionPlan $record): string => static::descriptionText($record))
                        ->color('gray')
                        ->wrap()
                        ->limit(150),

                    Tables\Columns\TextColumn::make('monthly_price')
                        ->label('В месяц')
                        ->state(fn(SubscriptionPlan $record): string => static::monthlyPriceLabel($record) ?? '')
                        ->color('gray'),

                    Tables\Columns\TextColumn::make('features_summary')
                        ->label('Что входит')
                        ->state(fn(SubscriptionPlan $record): array => static::featuresSummary($record))
                        ->color('gray')
                        ->size(TextSize::Small)
                        ->bulleted()
                        ->wrap(),
                ])
                    ->space(3),
            ])
            ->contentGrid([
                'md' => 2,
                'xl' => 3,
            ])
            ->defaultSort('sort_order')
            ->paginated(false)
            ->headerActions([
                CreateAction::make()
                    ->visible(fn(): bool => (bool)auth()->user()?->is_root),
            ])
            ->recordActions([
                Action::make('request_invoice')
                    ->label('Запросить счет')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('warning')
                    ->visible(fn(): bool => auth()->check() && !(bool)auth()->user()?->is_root)
                    ->form([
                        Forms\Components\Select::make('widget')
                            ->label('Виджет')
                            ->options(WidgetSubscriptionResource::widgetOptions())
                            ->searchable()
                            ->default(fn(SubscriptionPlan $record): ?string => $record->widget)
                            ->disabled(fn(SubscriptionPlan $record): bool => filled($record->widget))
                            ->dehydrated()
                            ->required(),
                        Forms\Components\Textarea::make('comment')
                            ->label('Комментарий')
                            ->rows(3),
                    ])
                    ->action(function (SubscriptionPlan $record, array $data): void {
                        $user = Auth::user();

                        SubscriptionInvoiceRequest::query()->create([
                            'user_id' => $user instanceof User ? $user->id : null,
                            'subscription_plan_id' => $record->id,
                            'widget' => $data['widget'] ?? null,
                            'status' => SubscriptionInvoiceRequest::STATUS_NEW,
                            'contact_name' => $user?->name,
                            'contact_email' => $user?->email,
                            'comment' => $data['comment'] ?? null,
                        ]);

                        Notification::make()
                            ->title('Заявка отправлена')
                            ->body('Мы увидим ее в поддержке и свяжемся с вами.')
                            ->success()
                            ->send();
                    }),
                EditAction::make()
                    ->visible(fn(): bool => (bool)auth()->user()?->is_root),
                DeleteAction::make()
                    ->visible(fn(): bool => (bool)auth()->user()?->is_root),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    private static function widgetTitle(SubscriptionPlan $record): string
    {
        if (filled($record->widget)) {
            return App::getTitle((string)$record->widget);
        }

        return $record->name;
    }

    /**
     * @return array<int, string>
     */
    private static function installedWidgetNamesForCurrentUser(): array
    {
        $userId = auth()->id();

        if (!$userId) {
            return [];
        }

        return App::query()
            ->where('user_id', $userId)
            ->whereIn('name', App::definitionNames())
            ->pluck('name')
            ->unique()
            ->values()
            ->all();
    }

    private static function periodLabel(SubscriptionPlan $record): string
    {
        return match ((int)$record->period_days) {
            30 => '1 месяц',
            180 => '6 месяцев',
            365 => '12 месяцев',
            default => filled($record->period_days) ? $record->period_days . ' дн.' : 'Период по запросу',
        };
    }

    private static function descriptionText(SubscriptionPlan $record): string
    {
        if (filled($record->description)) {
            return (string)$record->description;
        }

        if (filled($record->widget)) {
            return App::getTooltipText((string)$record->widget) ?: 'Доступ к виджету и поддержка подключения.';
        }

        return 'Доступ к виджету и поддержка подключения.';
    }

    private static function monthlyPriceLabel(SubscriptionPlan $record): ?string
    {
        $periodDays = (int)$record->period_days;
        $price = (int)$record->price_rub;

        if ($price <= 0 || $periodDays <= 30) {
            return null;
        }

        $months = match ($periodDays) {
            180 => 6,
            365 => 12,
            default => max(1, (int)round($periodDays / 30)),
        };

        return 'примерно ' . number_format((int)floor($price / $months), 0, '.', ' ') . ' ₽/мес.';
    }

    /**
     * @return array<int, string>
     */
    private static function featuresSummary(SubscriptionPlan $record): array
    {
        $features = collect($record->features ?? [])
            ->filter(fn(mixed $feature): bool => filled($feature))
            ->map(fn(mixed $feature): string => trim((string)$feature))
            ->take(3)
            ->values()
            ->all();

        if ($features !== []) {
            return $features;
        }

        return [
            'Ручное продление через поддержку',
            'Уведомления до окончания доступа',
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptionPlans::route('/'),
            'create' => Pages\CreateSubscriptionPlan::route('/create'),
            'edit' => Pages\EditSubscriptionPlan::route('/{record}/edit'),
        ];
    }
}

<?php

namespace App\Filament\Resources\Billing;

use App\Filament\Resources\Billing\SubscriptionPlanResource\Pages;
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
use Filament\Tables;
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
            $query->active();
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
                Tables\Columns\TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('price_label')
                    ->label('Цена')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('period_days')
                    ->label('Период')
                    ->suffix(' дн.')
                    ->placeholder('—')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Сорт.')
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptionPlans::route('/'),
            'create' => Pages\CreateSubscriptionPlan::route('/create'),
            'edit' => Pages\EditSubscriptionPlan::route('/{record}/edit'),
        ];
    }
}

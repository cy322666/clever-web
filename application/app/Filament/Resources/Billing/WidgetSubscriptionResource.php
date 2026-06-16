<?php

namespace App\Filament\Resources\Billing;

use App\Filament\Resources\Billing\WidgetSubscriptionResource\Pages;
use App\Models\App;
use App\Models\Billing\WidgetSubscription;
use App\Services\Billing\WidgetSubscriptionAccessService;
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

class WidgetSubscriptionResource extends Resource
{
    protected static ?string $model = WidgetSubscription::class;

    protected static ?string $navigationLabel = 'Оплата';

    protected static ?string $modelLabel = 'Подписка';

    protected static ?string $pluralModelLabel = 'Оплата';

    protected static string|\UnitEnum|null $navigationGroup = 'Оплата';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-lock-open';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

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

    public static function canView(Model $record): bool
    {
        if ((bool)auth()->user()?->is_root) {
            return true;
        }

        return auth()->check() && (int)$record->user_id === (int)auth()->id();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if ((bool)auth()->user()?->is_root) {
            return $query;
        }

        return $query->where('user_id', auth()->id());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make()
                ->schema([
                    Forms\Components\Select::make('user_id')
                        ->label('Пользователь')
                        ->relationship('user', 'email')
                        ->searchable()
                        ->preload()
                        ->required(),
                    Forms\Components\Select::make('app_id')
                        ->label('Установка виджета')
                        ->relationship(
                            'app',
                            'name',
                            fn($query) => $query->where('status', '!=', App::STATE_CREATED)->latest('id'),
                        )
                        ->getOptionLabelFromRecordUsing(fn(App $record): string => sprintf(
                            '%s · %s',
                            App::getTitle((string)$record->name, $record->resource_name),
                            $record->user?->email ?: ('User #' . $record->user_id),
                        ))
                        ->searchable()
                        ->preload(),
                    Forms\Components\Select::make('widget')
                        ->label('Виджет')
                        ->options(static::widgetOptions())
                        ->searchable()
                        ->required(),
                    Forms\Components\Select::make('subscription_plan_id')
                        ->label('Тариф')
                        ->relationship('plan', 'name')
                        ->searchable()
                        ->preload(),
                    Forms\Components\Select::make('status')
                        ->label('Статус')
                        ->options(WidgetSubscription::statusOptions())
                        ->default(WidgetSubscription::STATUS_ACTIVE)
                        ->required(),
                    Forms\Components\DatePicker::make('starts_at')
                        ->label('Начало')
                        ->native(false),
                    Forms\Components\DatePicker::make('ends_at')
                        ->label('Окончание')
                        ->native(false),
                    Forms\Components\DatePicker::make('grace_until')
                        ->label('Льготный период до')
                        ->native(false),
                    Forms\Components\DateTimePicker::make('blocked_at')
                        ->label('Заблокирована')
                        ->native(false),
                    Forms\Components\Textarea::make('notes')
                        ->label('Комментарий')
                        ->rows(3)
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.email')
                    ->label('Клиент')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('widget')
                    ->label('Виджет')
                    ->formatStateUsing(fn(string $state): string => App::getTitle($state))
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('plan.name')
                    ->label('Тариф')
                    ->placeholder('—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn(string $state): string => WidgetSubscription::statusOptions()[$state] ?? $state)
                    ->color(fn(string $state): string => match ($state) {
                        WidgetSubscription::STATUS_ACTIVE, WidgetSubscription::STATUS_TRIAL => 'success',
                        WidgetSubscription::STATUS_GRACE => 'warning',
                        WidgetSubscription::STATUS_EXPIRED,
                        WidgetSubscription::STATUS_BLOCKED,
                        WidgetSubscription::STATUS_CANCELLED => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('ends_at')
                    ->label('Окончание')
                    ->date('d.m.Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('grace_until')
                    ->label('Льгота')
                    ->date('d.m.Y')
                    ->placeholder('—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Обновлена')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->headerActions([
                Action::make('invoice_requests')
                    ->label('Заявки на счет')
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->visible(fn(): bool => (bool)auth()->user()?->is_root)
                    ->url(fn(): string => InvoiceRequestResource::getUrl('index')),
                CreateAction::make()
                    ->visible(fn(): bool => (bool)auth()->user()?->is_root),
            ])
            ->recordActions([
                Action::make('extend_30')
                    ->label('Продлить 30 дней')
                    ->icon('heroicon-o-calendar-days')
                    ->color('success')
                    ->action(function (WidgetSubscription $record): void {
                        $base = $record->ends_at?->isFuture() ? $record->ends_at->copy() : now();

                        $record->ends_at = $base->addDays(30)->toDateString();
                        $record->status = WidgetSubscription::STATUS_ACTIVE;
                        $record->blocked_at = null;
                        $record->save();

                        app(WidgetSubscriptionAccessService::class)->syncSubscriptionToLegacyApp($record);

                        Notification::make()
                            ->title('Подписка продлена')
                            ->success()
                            ->send();
                    })
                    ->visible(fn(): bool => (bool)auth()->user()?->is_root),
                EditAction::make()
                    ->visible(fn(): bool => (bool)auth()->user()?->is_root),
                DeleteAction::make()
                    ->visible(fn(): bool => (bool)auth()->user()?->is_root),
            ])
            ->recordUrl(fn(WidgetSubscription $record): ?string => (bool)auth()->user()?->is_root
                ? static::getUrl('edit', ['record' => $record])
                : null)
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn(): bool => (bool)auth()->user()?->is_root),
                ]),
            ]);
    }

    public static function widgetOptions(): array
    {
        return App::definitions()
            ->mapWithKeys(fn(array $definition, string $name): array => [
                $name => App::getTitle($name, $definition['resource'] ?? null),
            ])
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWidgetSubscriptions::route('/'),
            'create' => Pages\CreateWidgetSubscription::route('/create'),
            'edit' => Pages\EditWidgetSubscription::route('/{record}/edit'),
        ];
    }
}

<?php

namespace App\Filament\Resources\Billing;

use App\Filament\Resources\Billing\InvoiceRequestResource\Pages;
use App\Models\App;
use App\Models\Billing\SubscriptionInvoiceRequest;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class InvoiceRequestResource extends Resource
{
    protected static ?string $model = SubscriptionInvoiceRequest::class;

    protected static ?string $navigationLabel = 'Заявки на счет';

    protected static ?string $modelLabel = 'Заявка на счет';

    protected static ?string $pluralModelLabel = 'Заявки на счет';

    protected static string|\UnitEnum|null $navigationGroup = 'Подписки';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    public static function canViewAny(): bool
    {
        return (bool)auth()->user()?->is_root;
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

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make()
                ->schema([
                    Forms\Components\Select::make('user_id')
                        ->label('Пользователь')
                        ->relationship('user', 'email')
                        ->searchable()
                        ->preload(),
                    Forms\Components\Select::make('subscription_plan_id')
                        ->label('Тариф')
                        ->relationship('plan', 'name')
                        ->searchable()
                        ->preload(),
                    Forms\Components\Select::make('widget')
                        ->label('Виджет')
                        ->options(WidgetSubscriptionResource::widgetOptions())
                        ->searchable(),
                    Forms\Components\Select::make('status')
                        ->label('Статус')
                        ->options(SubscriptionInvoiceRequest::statusOptions())
                        ->default(SubscriptionInvoiceRequest::STATUS_NEW)
                        ->required(),
                    Forms\Components\TextInput::make('contact_name')
                        ->label('Контактное лицо')
                        ->maxLength(255),
                    Forms\Components\TextInput::make('contact_email')
                        ->label('Email')
                        ->email()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('contact_phone')
                        ->label('Телефон')
                        ->tel()
                        ->maxLength(255),
                    Forms\Components\DateTimePicker::make('resolved_at')
                        ->label('Закрыта')
                        ->native(false),
                    Forms\Components\Textarea::make('comment')
                        ->label('Комментарий клиента')
                        ->rows(3)
                        ->columnSpanFull(),
                    Forms\Components\Textarea::make('manager_note')
                        ->label('Комментарий менеджера')
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
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Создана')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.email')
                    ->label('Клиент')
                    ->placeholder('—')
                    ->searchable(),
                Tables\Columns\TextColumn::make('widget')
                    ->label('Виджет')
                    ->formatStateUsing(fn(?string $state): string => $state ? App::getTitle($state) : '—')
                    ->placeholder('—')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('plan.name')
                    ->label('Тариф')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn(string $state): string => SubscriptionInvoiceRequest::statusOptions()[$state] ?? $state)
                    ->color(fn(string $state): string => match ($state) {
                        SubscriptionInvoiceRequest::STATUS_NEW => 'warning',
                        SubscriptionInvoiceRequest::STATUS_IN_PROGRESS,
                        SubscriptionInvoiceRequest::STATUS_INVOICE_SENT => 'info',
                        SubscriptionInvoiceRequest::STATUS_PAID_MANUAL => 'success',
                        SubscriptionInvoiceRequest::STATUS_CANCELLED => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('contact_email')
                    ->label('Email')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('contact_phone')
                    ->label('Телефон')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
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
            'index' => Pages\ListInvoiceRequests::route('/'),
            'create' => Pages\CreateInvoiceRequest::route('/create'),
            'edit' => Pages\EditInvoiceRequest::route('/{record}/edit'),
        ];
    }
}

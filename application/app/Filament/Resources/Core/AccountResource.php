<?php

namespace App\Filament\Resources\Core;

use App\Filament\Resources\Core\AccountResource\Pages;
use App\Filament\Resources\UserResource;
use App\Models\Core\Account;
use Filament\Forms;
use Filament\Resources\Form;
use Filament\Resources\Resource;
use Filament\Resources\Table;
use Filament\Tables;

class AccountResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static ?string $navigationIcon = 'heroicon-o-collection';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('client_id'),
                Forms\Components\TextInput::make('client_secret'),
                Forms\Components\TextInput::make('redirect_uri'),
                Forms\Components\TextInput::make('code'),
                Forms\Components\TextInput::make('subdomain'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->searchable()
                    ->toggleable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('subdomain')
                    ->label('Поддомен'),

//                Tables\Columns\TextColumn::make('access_token')
//                    ->label('Токен'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Статус'),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Клиент')
                    ->searchable()
                    ->sortable()
                    ->url(fn ($record) => UserResource::getUrl('edit', ['record' => $record]))
                    ->toggleable(),
//
//                Tables\Columns\BadgeColumn::make('status.name')
//                    ->label('Статус')
//                    ->colors([
//                        'primary' => fn ($state): bool => true,
//                        'danger'  => fn ($state): bool => $state === OrderStatus::LOST_STATUS_NAME,
//                        'warning' => fn ($state): bool => $state === OrderStatus::NEW_STATUS_NAME,
//                        'success' => fn ($state): bool => $state === OrderStatus::WIN_STATUS_NAME,
//                    ])
//                    ->sortable(),
//
//                Tables\Columns\TextColumn::make('price')
//                    ->label('Бюджет')
//                    ->searchable()
//                    ->sortable(),
//
//                Tables\Columns\TextColumn::make('customer.name')
//                    ->label('Клиент')
//                    ->searchable()
//                    ->sortable(),
//
////                Tables\Columns\TextColumn::make('pay_parts')
////                    ->label('Платежей')
////                    ->toggleable()
////                    ->sortable()
////                    ->toggledHiddenByDefault(true),
//
//                Tables\Columns\TextColumn::make('responsible.name')
//                    ->label('Ответственный')
//                    ->sortable(),
//
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
//
//                Tables\Columns\TextColumn::make('updated_at')
//                    ->label('Обновлен')
//                    ->dateTime()
//                    ->sortable()
//                    ->toggleable()
//                    ->toggledHiddenByDefault(true),
//
//                Tables\Columns\TextColumn::make('source.name')
//                    ->label('Источник')
//                    ->sortable()
//                    ->toggleable()
//                    ->toggledHiddenByDefault(true),
//
//                Tables\Columns\TextColumn::make('reason.name')
//                    ->label('Причина отказа')
//                    ->sortable()
//                    ->toggleable(true)
//                    ->toggledHiddenByDefault(true),
            ])
            ->filters([
                //
            ])
            ->actions([
//                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
//                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAccounts::route('/'),
//            'create' => Pages\CreateAccount::route('/create'),
            'edit' => Pages\EditAccount::route('/{record}/edit'),
        ];
    }
}

<?php

namespace App\Filament\Resources\Core;

use App\Filament\Resources\Core\AuthenticationLogResource\Pages\ListAuthenticationLogs;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Rappasoft\LaravelAuthenticationLog\Models\AuthenticationLog;

class AuthenticationLogResource extends Resource
{
    protected static ?string $model = AuthenticationLog::class;

    protected static ?string $slug = 'authentication-logs';

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn(Builder $query) => $query->with('authenticatable'))
            ->defaultSort(
                config('filament-authentication-log.sort.column'),
                config('filament-authentication-log.sort.direction'),
            )
            ->columns([
                TextColumn::make('authenticatable')
                    ->label('Пользователь')
                    ->formatStateUsing(function (?string $state, Model $record) {
                        if (!$record->authenticatable_id || !$record->authenticatable) {
                            return new HtmlString('&mdash;');
                        }

                        $authenticatableFieldToDisplay = config(
                            'filament-authentication-log.authenticatable.field-to-display'
                        );
                        $authenticatableDisplay = $authenticatableFieldToDisplay !== null
                            ? $record->authenticatable->{$authenticatableFieldToDisplay}
                            : class_basename($record->authenticatable::class);

                        $authenticableEditRoute = '#';
                        $routeName = 'filament.app.resources.' . Str::plural(
                                Str::lower(class_basename($record->authenticatable::class))
                            ) . '.edit';

                        if (Route::has($routeName)) {
                            $authenticableEditRoute = route($routeName, ['record' => $record->authenticatable_id]);
                        } elseif (config('filament-authentication-log.user-resource')) {
                            $authenticableEditRoute = static::getCustomUserRoute($record);
                        }

                        return new HtmlString(
                            '<a href="' . $authenticableEditRoute . '" class="inline-flex items-center justify-center text-sm font-medium hover:underline focus:outline-none focus:underline filament-tables-link text-primary-600 hover:text-primary-500 filament-tables-link-action">' .
                            $authenticatableDisplay .
                            '</a>'
                        );
                    })
                    ->sortable(['authenticatable_id']),

                TextColumn::make('ip_address')
                    ->label('IP-адрес')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('user_agent')
                    ->label('Устройство')
                    ->searchable()
                    ->sortable()
                    ->limit(50)
                    ->tooltip(function (TextColumn $column): ?string {
                        $state = $column->getState();
                        if (strlen($state) <= $column->getCharacterLimit()) {
                            return null;
                        }

                        return $state;
                    }),

                TextColumn::make('login_at')
                    ->label('Время входа')
                    ->dateTime()
                    ->sortable(),

                IconColumn::make('login_successful')
                    ->label('Успешный вход')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('logout_at')
                    ->label('Время выхода')
                    ->dateTime()
                    ->sortable(),

                IconColumn::make('cleared_by_user')
                    ->label('Сброшено пользователем')
                    ->boolean()
                    ->sortable(),
            ])
            ->recordActions([
                //
            ])
            ->filters([
                Filter::make('login_successful')
                    ->label('Только успешные входы')
                    ->toggle()
                    ->query(fn(Builder $query): Builder => $query->where('login_successful', true)),

                Filter::make('login_at')
                    ->label('Период входа')
                    ->schema([
                        DatePicker::make('login_from')
                            ->label('С даты'),
                        DatePicker::make('login_until')
                            ->label('По дату'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['login_from'],
                                fn(Builder $query, $date): Builder => $query->whereDate('login_at', '>=', $date),
                            )
                            ->when(
                                $data['login_until'],
                                fn(Builder $query, $date): Builder => $query->whereDate('login_at', '<=', $date),
                            );
                    }),

                Filter::make('cleared_by_user')
                    ->label('Сброшено пользователем')
                    ->toggle()
                    ->query(fn(Builder $query): Builder => $query->where('cleared_by_user', true)),
            ]);
    }

    protected static function getCustomUserRoute(Model $record): string
    {
        $userResource = config('filament-authentication-log.user-resource');

        if (
            is_string($userResource)
            && method_exists($userResource, 'getUrl')
            && method_exists($userResource, 'hasPage')
            && $userResource::hasPage('edit')
        ) {
            return $userResource::getUrl('edit', ['record' => $record->authenticatable_id]);
        }

        return '#';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuthenticationLogs::route('/'),
        ];
    }
}

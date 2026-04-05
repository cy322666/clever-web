<?php

namespace App\Filament\Resources\Core;

use Filament\Forms\Components\DatePicker;
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
use Tapp\FilamentAuthenticationLog\FilamentAuthenticationLogPlugin;
use Tapp\FilamentAuthenticationLog\Resources\AuthenticationLogResource as BaseAuthenticationLogResource;

class AuthenticationLogResource extends BaseAuthenticationLogResource
{
    protected static ?string $slug = 'authentication-logs';

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
                    ->label(trans('filament-authentication-log::filament-authentication-log.column.authenticatable'))
                    ->formatStateUsing(function (?string $state, Model $record) {
                        $authenticatableFieldToDisplay = config(
                            'filament-authentication-log.authenticatable.field-to-display'
                        );
                        $authenticatableDisplay = $authenticatableFieldToDisplay !== null
                            ? $record->authenticatable->{$authenticatableFieldToDisplay}
                            : class_basename($record->authenticatable::class);

                        if (!$record->authenticatable_id) {
                            return new HtmlString('&mdash;');
                        }

                        $authenticableEditRoute = '#';
                        $routeName = 'filament.' . FilamentAuthenticationLogPlugin::get()->getPanelName() .
                            '.resources.' . Str::plural(
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
                    ->label(trans('filament-authentication-log::filament-authentication-log.column.ip_address'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('user_agent')
                    ->label(trans('filament-authentication-log::filament-authentication-log.column.user_agent'))
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
                    ->label(trans('filament-authentication-log::filament-authentication-log.column.login_at'))
                    ->dateTime()
                    ->sortable(),

                IconColumn::make('login_successful')
                    ->label(trans('filament-authentication-log::filament-authentication-log.column.login_successful'))
                    ->boolean()
                    ->sortable(),

                TextColumn::make('logout_at')
                    ->label(trans('filament-authentication-log::filament-authentication-log.column.logout_at'))
                    ->dateTime()
                    ->sortable(),

                IconColumn::make('cleared_by_user')
                    ->label(trans('filament-authentication-log::filament-authentication-log.column.cleared_by_user'))
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
}

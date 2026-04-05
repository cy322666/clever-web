<?php

namespace App\Filament\App\Widgets;

use App\Models\Core\ApiRequest;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class ApiRequestsTable extends TableWidget
{
    protected static ?string $pollingInterval = '30s';
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(ApiRequest::query()->orderByDesc('created_at'))
            ->heading('Входящие API запросы')
            ->description('По умолчанию показаны последние 24 часа')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Время')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),

                TextColumn::make('method')
                    ->label('Метод')
                    ->badge()
                    ->color(fn(string $state): string => match (strtoupper($state)) {
                        'GET' => 'info',
                        'POST' => 'success',
                        'PUT', 'PATCH' => 'warning',
                        'DELETE' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('path')
                    ->label('Путь')
                    ->searchable()
                    ->limit(70)
                    ->tooltip(fn(string $state): string => $state),

                TextColumn::make('status_code')
                    ->label('Статус')
                    ->badge()
                    ->color(fn(int $state): string => $state >= 500 ? 'danger' : ($state >= 400 ? 'warning' : 'success')
                    )
                    ->sortable(),

                TextColumn::make('duration_ms')
                    ->label('Время, мс')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('user_uuid')
                    ->label('User UUID')
                    ->copyable()
                    ->toggleable(),

                TextColumn::make('ip_address')
                    ->label('IP')
                    ->toggleable(),

                TextColumn::make('route_name')
                    ->label('Route')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('payload')
                    ->label('Payload')
                    ->state(function (ApiRequest $record): string {
                        if (empty($record->payload)) {
                            return '—';
                        }

                        return (string)json_encode($record->payload, JSON_UNESCAPED_UNICODE);
                    })
                    ->limit(80)
                    ->tooltip(function (ApiRequest $record): string {
                        if (empty($record->payload)) {
                            return '—';
                        }

                        return (string)json_encode($record->payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('period')
                    ->label('Период')
                    ->options([
                        '24h' => 'Последние 24 часа',
                        '7d' => 'Последние 7 дней',
                        '30d' => 'Последние 30 дней',
                        'all' => 'Все',
                    ])
                    ->default('24h')
                    ->query(function (Builder $query, array $data): Builder {
                        $value = (string)($data['value'] ?? '24h');

                        return match ($value) {
                            '7d' => $query->where('created_at', '>=', now()->subDays(7)),
                            '30d' => $query->where('created_at', '>=', now()->subDays(30)),
                            'all' => $query,
                            default => $query->where('created_at', '>=', now()->subDay()),
                        };
                    }),

                SelectFilter::make('status_group')
                    ->label('Статусы')
                    ->options([
                        '2xx' => '2xx',
                        '4xx' => '4xx',
                        '5xx' => '5xx',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = (string)($data['value'] ?? '');

                        return match ($value) {
                            '2xx' => $query->whereBetween('status_code', [200, 299]),
                            '4xx' => $query->whereBetween('status_code', [400, 499]),
                            '5xx' => $query->whereBetween('status_code', [500, 599]),
                            default => $query,
                        };
                    }),
            ])
            ->defaultPaginationPageOption(50);
    }
}

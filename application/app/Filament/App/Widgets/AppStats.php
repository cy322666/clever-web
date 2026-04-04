<?php

namespace App\Filament\App\Widgets;

use App\Support\AppStats\AppStatsAggregator;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class AppStats extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    public function getTableRecords(): \Illuminate\Support\Collection
    {
        return AppStatsAggregator::perApp();
    }

    public function getTableRecordKey($record): string
    {
        return (string) $record['key'];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => $this->getTableRecords())
            ->heading('Приложения')
            ->description('Детализация по активности, просрочкам и нагрузке')
            ->columns([
                TextColumn::make('name')
                    ->label('Приложение')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('count_installs')
                    ->label('Установлено')
                    ->badge()
                    ->alignCenter()
                    ->color('info')
                    ->sortable(),

                TextColumn::make('count_active')
                    ->label('Активно')
                    ->badge()
                    ->alignCenter()
                    ->color('success')
                    ->sortable(),

                TextColumn::make('count_expired')
                    ->label('Просрочено')
                    ->badge()
                    ->alignCenter()
                    ->color('danger')
                    ->sortable(),

                TextColumn::make('activation_rate')
                    ->label('Конверсия')
                    ->alignCenter()
                    ->suffix('%')
                    ->color(fn(float $state): string => $state >= 75 ? 'success' : ($state >= 50 ? 'warning' : 'danger')
                    )
                    ->sortable(),

                TextColumn::make('count_users_active')
                    ->label('Клиентов с активной интеграцией')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('count_transactions')
                    ->label('Транзакций')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('last_install_at')
                    ->label('Последняя установка')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->paginated(false);
    }
}

<?php

namespace App\Filament\Resources\Billing\Widgets;

use App\Models\App;
use App\Models\Billing\WidgetSubscription;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class CurrentSubscriptionsTable extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn(): Builder => $this->getSubscriptionsQuery())
            ->heading('Подписки')
            ->columns([
                Tables\Columns\TextColumn::make('user.email')
                    ->label('Клиент')
                    ->searchable()
                    ->sortable()
                    ->visible(fn(): bool => (bool)auth()->user()?->is_root),
                Tables\Columns\TextColumn::make('widget')
                    ->label('Виджет')
                    ->formatStateUsing(fn(?string $state): string => filled($state) ? App::getTitle($state) : '—')
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
                    ->label('Истекает')
                    ->date('d.m.Y')
                    ->placeholder('—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('grace_until')
                    ->label('Льгота')
                    ->date('d.m.Y')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Подписок пока нет')
            ->emptyStateDescription('Здесь появятся активные и пробные подписки по установленным виджетам.')
            ->paginated([10, 25, 50]);
    }

    private function getSubscriptionsQuery(): Builder
    {
        $query = WidgetSubscription::query()
            ->with(['plan', 'user'])
            ->latest('id');

        if ((bool)auth()->user()?->is_root) {
            return $query;
        }

        return $query->where('user_id', auth()->id());
    }
}

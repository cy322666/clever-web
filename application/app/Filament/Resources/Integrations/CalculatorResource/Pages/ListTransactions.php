<?php

namespace App\Filament\Resources\Integrations\CalculatorResource\Pages;

use App\Filament\Resources\Integrations\CalculatorResource;
use App\Models\Integrations\Calculator\Transaction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListTransactions extends ListRecords
{
    protected static string $resource = CalculatorResource::class;

    protected static ?string $title = 'История расчетов';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn(?int $state): string => match ($state) {
                        Transaction::STATUS_SUCCESS => 'Успех',
                        Transaction::STATUS_ERROR => 'Ошибка',
                        default => 'В очереди',
                    })
                    ->color(fn(?int $state): string => match ($state) {
                        Transaction::STATUS_SUCCESS => 'success',
                        Transaction::STATUS_ERROR => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('entity_type')
                    ->label('Сущность')
                    ->formatStateUsing(fn(?string $state): string => match ($state) {
                        'lead' => 'Сделка',
                        'contact' => 'Контакт',
                        'company' => 'Компания',
                        default => $state ?: '-',
                    })
                    ->badge(),

                Tables\Columns\TextColumn::make('entity_id')
                    ->label('ID')
                    ->url(fn(Transaction $transaction): string => $this->amoEntityUrl($transaction), true)
                    ->placeholder('-')
                    ->sortable(),

                Tables\Columns\TextColumn::make('field_name')
                    ->label('Поле')
                    ->state(fn(Transaction $transaction): string => $transaction->field_name ?: (string)($transaction->field_id ?: '-'))
                    ->limit(28)
                    ->tooltip(fn(Transaction $transaction): ?string => $transaction->field_name ?: $transaction->field_id),

                Tables\Columns\TextColumn::make('result_value')
                    ->label('Результат')
                    ->placeholder('-')
                    ->copyable()
                    ->limit(24),

                Tables\Columns\TextColumn::make('expression')
                    ->label('Формула')
                    ->limit(36)
                    ->tooltip(fn(Transaction $transaction): ?string => $transaction->expression)
                    ->wrap(),

                Tables\Columns\TextColumn::make('error_message')
                    ->label('Ошибка')
                    ->limit(36)
                    ->tooltip(fn(Transaction $transaction): ?string => $transaction->error_message)
                    ->wrap()
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('workflow_id')
                    ->label('Процесс')
                    ->placeholder('-')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        Transaction::STATUS_PENDING => 'В очереди',
                        Transaction::STATUS_SUCCESS => 'Успех',
                        Transaction::STATUS_ERROR => 'Ошибка',
                    ]),

                Tables\Filters\SelectFilter::make('entity_type')
                    ->label('Сущность')
                    ->options([
                        'lead' => 'Сделка',
                        'contact' => 'Контакт',
                        'company' => 'Компания',
                    ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([50, 100])
            ->defaultPaginationPageOption(50)
            ->actions([])
            ->bulkActions([])
            ->emptyStateActions([]);
    }

    protected function getTableQuery(): ?Builder
    {
        $query = Transaction::query()
            ->with(['user', 'account']);

        if (!Auth::user()?->is_root) {
            $query->where('user_id', Auth::id());
        }

        return $query;
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    private function amoEntityUrl(Transaction $transaction): string
    {
        if (!$transaction->entity_type || !$transaction->entity_id) {
            return '#';
        }

        $subdomain = $transaction->account?->subdomain;

        if (!$subdomain) {
            $user = Auth::user()?->is_root ? $transaction->user : Auth::user();
            $subdomain = $user?->resolveAmoAccountForWidget('calculator')?->subdomain;
        }

        if (!$subdomain) {
            return '#';
        }

        $path = match ($transaction->entity_type) {
            'lead' => 'leads/detail',
            'contact' => 'contacts/detail',
            'company' => 'companies/detail',
            default => null,
        };

        return $path
            ? 'https://' . $subdomain . '.amocrm.ru/' . $path . '/' . $transaction->entity_id
            : '#';
    }
}

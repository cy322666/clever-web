<?php

namespace App\Filament\App\Widgets;

use Croustibat\FilamentJobsMonitor\Models\FailedJob;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FailedJobsTable extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(FailedJob::query()->orderByDesc('failed_at'))
            ->heading('Упавшие задания')
            ->description(
                'Источник: таблица failed_jobs. Синхронизация с queue_monitors выполняется автоматически по расписанию.'
            )
            ->columns([
                TextColumn::make('failed_at')
                    ->label('Упало в')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),

                TextColumn::make('queue')
                    ->label('Очередь')
                    ->badge()
                    ->sortable(),

                TextColumn::make('connection')
                    ->label('Соединение')
                    ->sortable(),

                TextColumn::make('job_name')
                    ->label('Job')
                    ->state(fn(FailedJob $record): string => self::jobName($record))
                    ->limit(70),

                TextColumn::make('exception')
                    ->label('Ошибка')
                    ->state(fn(FailedJob $record): string => self::shortException($record))
                    ->limit(90)
                    ->tooltip(fn(FailedJob $record): string => (string)($record->exception ?? '-')),

                TextColumn::make('uuid')
                    ->label('UUID')
                    ->copyable()
                    ->toggleable(),
            ])
            ->headerActions([
                Action::make('retry_all_failed')
                    ->label('Retry all failed')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (): void {
                        Artisan::call('queue:retry', ['id' => ['all']]);

                        Notification::make()
                            ->title('Команда queue:retry all отправлена')
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                Action::make('retry')
                    ->label('Retry')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->action(function (FailedJob $record): void {
                        $identifier = (string)($record->uuid ?: $record->id);

                        Artisan::call('queue:retry', ['id' => [$identifier]]);

                        Notification::make()
                            ->title('Задание отправлено на повтор')
                            ->body('ID: ' . $identifier)
                            ->success()
                            ->send();
                    }),

                Action::make('forget')
                    ->label('Удалить')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (FailedJob $record): void {
                        $identifier = (string)($record->uuid ?: $record->id);

                        $code = Artisan::call('queue:forget', ['id' => $identifier]);

                        if ($code !== 0) {
                            DB::connection($record->getConnectionName())
                                ->table('failed_jobs')
                                ->where('id', $record->id)
                                ->delete();
                        }

                        Notification::make()
                            ->title('Запись удалена из failed_jobs')
                            ->body('ID: ' . $identifier)
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultPaginationPageOption(25);
    }

    private static function jobName(FailedJob $record): string
    {
        $payload = is_array($record->payload) ? $record->payload : [];

        return (string)($payload['displayName'] ?? $payload['job'] ?? 'Unknown Job');
    }

    private static function shortException(FailedJob $record): string
    {
        $exception = trim((string)($record->exception ?? ''));

        if ($exception === '') {
            return '-';
        }

        $firstLine = Str::of($exception)->before("\n")->toString();

        return Str::limit($firstLine, 140);
    }
}

<?php

namespace App\Filament\Resources\Integrations\Distribution\ScheduleResource\Pages;

use App\Filament\Resources\Integrations\Distribution\ScheduleResource;
use App\Filament\Resources\Integrations\Distribution\ScheduleResource\Widgets\DistributionScheduleCalendar;
use App\Filament\Resources\Integrations\DistributionResource;
use App\Models\Integrations\Distribution\Setting as DistributionSetting;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Auth;

class ListSchedule extends Page
{
    protected static string $resource = ScheduleResource::class;

    protected ?string $heading = 'График распределения';

    public function getHeaderWidgetsColumns(): int | array
    {
        return 1;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            DistributionScheduleCalendar::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('selectQueue')
                ->label('Очередь')
                ->icon('heroicon-o-queue-list')
                ->modalHeading('Очередь графика распределения')
                ->form([
                    Select::make('schedule_queue')
                        ->label('Очередь')
                        ->options(fn(): array => $this->queueOptions())
                        ->required()
                        ->native(false)
                        ->searchable(),
                ])
                ->fillForm(fn(): array => [
                    'schedule_queue' => $this->currentScheduleQueue(),
                ])
                ->action(function (array $data): void {
                    $queue = (string)($data['schedule_queue'] ?? $this->currentScheduleQueue());

                    session([$this->scheduleQueueSessionKey() => $queue]);

                    $this->redirect(ScheduleResource::getUrl('index') . '?queue=' . urlencode($queue));
                }),

            Actions\Action::make('settings')
                ->label('Настройки')
                ->icon('heroicon-o-cog-6-tooth')
                ->url(DistributionResource::getUrl('edit', ['record' => Auth::user()->distribution_settings->id])),
        ];
    }

    private function currentScheduleQueue(): ?string
    {
        $options = $this->queueOptions();
        $queryQueue = request()->query('queue');

        if (is_string($queryQueue) && array_key_exists($queryQueue, $options)) {
            session([$this->scheduleQueueSessionKey() => $queryQueue]);

            return $queryQueue;
        }

        $sessionQueue = session($this->scheduleQueueSessionKey());

        if (is_string($sessionQueue) && array_key_exists($sessionQueue, $options)) {
            return $sessionQueue;
        }

        return array_key_first($options);
    }

    private function scheduleQueueSessionKey(): string
    {
        return 'distribution.schedule.queue.' . (string)Auth::id();
    }

    /**
     * @return array<string, string>
     */
    private function queueOptions(): array
    {
        return collect($this->distributionQueues())
            ->mapWithKeys(fn(array $queue): array => [$queue['key'] => $queue['label']])
            ->all();
    }

    /**
     * @return array<string, array{key: string, label: string}>
     */
    private function distributionQueues(): array
    {
        $setting = DistributionSetting::query()
            ->where('user_id', Auth::id())
            ->latest('id')
            ->first(['settings']);

        $settings = json_decode($setting?->settings ?? '[]', true);

        if (!is_array($settings)) {
            return [];
        }

        $queues = [];

        foreach ($settings as $index => $queue) {
            if (!is_array($queue)) {
                continue;
            }

            $queueUuid = is_string($queue['queue_uuid'] ?? null) && $queue['queue_uuid'] !== ''
                ? $queue['queue_uuid']
                : null;
            $key = $queueUuid ?? (string)$index;
            $queues[$key] = [
                'key' => $key,
                'label' => $this->queueLabel($queue, (int)$index),
            ];
        }

        return $queues;
    }

    private function queueLabel(array $queue, int $index): string
    {
        $name = trim((string)($queue['name'] ?? ''));
        $strategy = match ((string)($queue['strategy'] ?? '')) {
            DistributionSetting::STRATEGY_ROTATION => 'по очереди',
            DistributionSetting::STRATEGY_RANDOM => 'вразброс',
            DistributionSetting::STRATEGY_SCHEDULE => 'по графику',
            default => null,
        };

        return trim(($name !== '' ? $name : 'Очередь #' . ($index + 1)) . ($strategy ? ' · ' . $strategy : ''));
    }
}

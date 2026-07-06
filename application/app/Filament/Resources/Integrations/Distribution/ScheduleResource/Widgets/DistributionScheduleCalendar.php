<?php

namespace App\Filament\Resources\Integrations\Distribution\ScheduleResource\Widgets;

use App\Models\amoCRM\Staff;
use App\Models\Integrations\Distribution\Setting as DistributionSetting;
use App\Services\Distribution\ScheduleSettingsService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Guava\Calendar\Enums\CalendarViewType;
use Guava\Calendar\Filament\CalendarWidget;
use Guava\Calendar\ValueObjects\CalendarEvent;
use Guava\Calendar\ValueObjects\CalendarResource;
use Guava\Calendar\ValueObjects\DateSelectInfo;
use Guava\Calendar\ValueObjects\EventDropInfo;
use Guava\Calendar\ValueObjects\EventClickInfo;
use Guava\Calendar\ValueObjects\EventResizeInfo;
use Guava\Calendar\ValueObjects\FetchInfo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Throwable;

class DistributionScheduleCalendar extends CalendarWidget
{
    private const COLOR_PRIMARY = '#fb923c';
    private const COLOR_PRIMARY_DARK = '#f97316';
    private const COLOR_MUTED_DARK = '#51463d';
    private const COLOR_TEXT_ON_PRIMARY = '#ffffff';

    protected HtmlString | string | bool | null $heading = false;

    protected CalendarViewType $calendarView = CalendarViewType::ResourceTimelineWeek;

    public ?string $scheduleQueue = null;

    protected bool $dateSelectEnabled = true;

    protected bool $eventClickEnabled = true;

    protected bool $eventDragEnabled = true;

    protected bool $eventResizeEnabled = true;

    protected ?string $defaultEventClickAction = null;

    protected array $options = [
        'height' => 'auto',
        'nowIndicator' => true,
        'slotMinWidth' => 72,
        'resourceAreaWidth' => '360px',
        'eventMinWidth' => 16,
        'headerToolbar' => [
            'start' => 'prev,next',
            'center' => 'title',
            'end' => 'resourceTimelineWeek,resourceTimelineMonth',
        ],
        'buttonText' => [
            'resourceTimelineWeek' => 'Неделя',
            'resourceTimelineMonth' => 'Месяц',
        ],
    ];

    public function getHeaderActions(): array
    {
        return [];
    }

    public function getHeading(): null|string|HtmlString
    {
        return 'График очереди: ' . $this->selectedQueueLabel();
    }

    public function configureScheduleAction(): Action
    {
        return Action::make('configureSchedule')
            ->label('Настроить график')
            ->icon('heroicon-o-calendar-days')
            ->slideOver()
            ->modalWidth('5xl')
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->form($this->scheduleFormSchema())
            ->fillForm(fn(): array => $this->defaultScheduleActionData())
            ->action(function (array $data): void {
                $staff = $this->selectedStaffQuery()->find($data['staff_id'] ?? null);

                if (!$staff) {
                    Notification::make()
                        ->title('Выберите сотрудника')
                        ->danger()
                        ->send();

                    return;
                }

                $payload = $this->scheduleSettings()->buildPayload($data);
                $queue = $this->selectedQueue();
                $this->scheduleSettings()->saveForStaff(
                    $staff,
                    $payload,
                    $queue['queue_uuid'] ?? null,
                    $queue['template'] ?? null,
                );

                $this->refreshRecords();

                Notification::make()
                    ->title('График сохранен')
                    ->success()
                    ->send();
            });
    }

    public function deleteSchedulePeriodAction(): Action
    {
        return Action::make('deleteSchedulePeriod')
            ->label('Удалить период')
            ->color('danger')
            ->modalHeading('Удалить период?')
            ->modalDescription('Период будет удален из графика сотрудника.')
            ->modalSubmitActionLabel('Удалить')
            ->requiresConfirmation()
            ->action(function (array $arguments): void {
                $this->deleteSchedulePeriodFromArguments($arguments);
            });
    }

    public function createExceptionAction(): Action
    {
        return Action::make('createException')
            ->label('Добавить исключение')
            ->modalHeading('Исключение в графике')
            ->form([
                DateTimePicker::make('from')
                    ->label('С')
                    ->seconds(false)
                    ->native(false)
                    ->required(),

                DateTimePicker::make('to')
                    ->label('По')
                    ->seconds(false)
                    ->native(false)
                    ->required(),
            ])
            ->fillForm(function (): array {
                $dateSelect = $this->currentDateSelectInfo();

                return [
                    'from' => $dateSelect?->start,
                    'to' => $dateSelect?->end,
                ];
            })
            ->action(function (array $data): void {
                $dateSelect = $this->currentDateSelectInfo();

                if (!$dateSelect?->resource) {
                    Notification::make()
                        ->title('Выберите диапазон на строке сотрудника')
                        ->danger()
                        ->send();

                    return;
                }

                $staff = $this->selectedStaffQuery()->find($dateSelect->resource->getId());

                if (!$staff) {
                    Notification::make()
                        ->title('Сотрудник не найден')
                        ->danger()
                        ->send();

                    return;
                }

                $from = Carbon::parse($data['from']);
                $to = Carbon::parse($data['to']);

                if ($from->gte($to)) {
                    Notification::make()
                        ->title('Конец периода должен быть позже начала')
                        ->danger()
                        ->send();

                    return;
                }

                $queue = $this->selectedQueue();
                $this->scheduleSettings()->addException($staff, [
                    'type' => 'work',
                    'from' => $from->format('Y-m-d H:i:s'),
                    'to' => $to->format('Y-m-d H:i:s'),
                ], $queue['queue_uuid'] ?? null, $queue['template'] ?? null);

                $this->refreshRecords();

                Notification::make()
                    ->title('Исключение добавлено')
                    ->success()
                    ->send();
            });
    }

    protected function onDateSelect(DateSelectInfo $info): void
    {
        $staff = $this->staffFromResourceId($info->resource?->getId());

        if (!$staff) {
            Notification::make()
                ->title('Выберите диапазон на строке сотрудника')
                ->danger()
                ->send();

            return;
        }

        $from = Carbon::parse($info->start);
        $to = Carbon::parse($info->end);

        if ($from->gte($to)) {
            Notification::make()
                ->title('Конец периода должен быть позже начала')
                ->danger()
                ->send();

            return;
        }

        try {
            $queue = $this->selectedQueue();
            $this->scheduleSettings()->addException($staff, [
                'type' => 'work',
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
            ], $queue['queue_uuid'] ?? null, $queue['template'] ?? null);
        } catch (Throwable $exception) {
            Notification::make()
                ->title($exception->getMessage() ?: 'Не удалось добавить период')
                ->danger()
                ->send();

            return;
        }

        $this->refreshRecords();

        Notification::make()
            ->title('Период добавлен')
            ->success()
            ->send();
    }

    protected function onEventClick(EventClickInfo $info, Model $event, ?string $action = null): void
    {
        if ($action === 'deleteSchedulePeriod') {
            $arguments = $this->getRawCalendarContextData() ?? [];

            if (($arguments['context'] ?? null) instanceof \BackedEnum) {
                $arguments['context'] = $arguments['context']->value;
            }

            $this->mountAction('deleteSchedulePeriod', $arguments);

            return;
        }
    }

    private function deleteSchedulePeriodFromArguments(array $arguments): void
    {
        $context = $arguments['context'] ?? null;
        $context = $context instanceof \BackedEnum ? $context->value : $context;

        if ($context !== 'eventClick') {
            Notification::make()
                ->title('Период не выбран')
                ->danger()
                ->send();

            return;
        }

        $this->rawCalendarContextData = $arguments;

        try {
            $this->resolveEventRecord();
        } catch (Throwable) {
            Notification::make()
                ->title('Период не выбран')
                ->danger()
                ->send();

            return;
        }

        $info = $this->getCalendarContextInfo();
        $event = $this->getEventRecord();

        if (!$info instanceof EventClickInfo || !$event instanceof Model) {
            Notification::make()
                ->title('Период не выбран')
                ->danger()
                ->send();

            return;
        }

        $this->deleteSchedulePeriod($info, $event);
    }

    private function deleteSchedulePeriod(EventClickInfo $info, Model $event): void
    {
        $staff = $event instanceof Staff && (int)$event->user_id === (int)Auth::id() && (bool)$event->active
            ? $event
            : $this->staffFromResourceId((int)($info->event->getResourceIds()[0] ?? 0));

        if (!$staff) {
            Notification::make()
                ->title('Сотрудник не найден')
                ->danger()
                ->send();

            return;
        }

        $props = $info->event->getExtendedProps();
        $kind = (string)($props['distribution_schedule_kind'] ?? 'base');
        $from = Carbon::parse($props['distribution_exception_from'] ?? $info->event->getStart())
            ->format('Y-m-d H:i:s');
        $to = Carbon::parse($props['distribution_exception_to'] ?? $info->event->getEnd())
            ->format('Y-m-d H:i:s');

        try {
            if ($kind === 'work_exception') {
                $queue = $this->selectedQueue();
                $removed = $this->scheduleSettings()->removeException(
                    $staff,
                    'work',
                    $from,
                    $to,
                    $queue['queue_uuid'] ?? null,
                    $queue['template'] ?? null,
                );

                if (!$removed) {
                    Notification::make()
                        ->title('Период уже не найден')
                        ->warning()
                        ->send();

                    return;
                }
            } else {
                $queue = $this->selectedQueue();
                $this->scheduleSettings()->addException($staff, [
                    'type' => 'free',
                    'from' => $from,
                    'to' => $to,
                ], $queue['queue_uuid'] ?? null, $queue['template'] ?? null);
            }
        } catch (Throwable $exception) {
            Notification::make()
                ->title($exception->getMessage() ?: 'Не удалось удалить период')
                ->danger()
                ->send();

            return;
        }

        $this->refreshRecords();

        Notification::make()
            ->title('Период удален')
            ->success()
            ->send();
    }

    protected function onEventDrop(EventDropInfo $info, Model $event): bool
    {
        return $this->updateWorkExceptionPeriod($info->oldEvent, $info->event, $event);
    }

    protected function onEventResize(EventResizeInfo $info, Model $event): bool
    {
        return $this->updateWorkExceptionPeriod($info->oldEvent, $info->event, $event);
    }

    private function updateWorkExceptionPeriod(CalendarEvent $oldEvent, CalendarEvent $newEvent, Model $event): bool
    {
        $props = $oldEvent->getExtendedProps();

        if (($props['distribution_schedule_kind'] ?? null) !== 'work_exception') {
            return false;
        }

        $staff = $event instanceof Staff && (int)$event->user_id === (int)Auth::id() && (bool)$event->active
            ? $event
            : $this->staffFromResourceId((int)($oldEvent->getResourceIds()[0] ?? 0));

        if (!$staff) {
            Notification::make()
                ->title('Сотрудник не найден')
                ->danger()
                ->send();

            return false;
        }

        $oldFrom = Carbon::parse($props['distribution_exception_from'] ?? $oldEvent->getStart())
            ->format('Y-m-d H:i:s');
        $oldTo = Carbon::parse($props['distribution_exception_to'] ?? $oldEvent->getEnd())
            ->format('Y-m-d H:i:s');
        $newFrom = Carbon::parse($newEvent->getStart())->format('Y-m-d H:i:s');
        $newTo = Carbon::parse($newEvent->getEnd())->format('Y-m-d H:i:s');

        try {
            $queue = $this->selectedQueue();
            $updated = $this->scheduleSettings()->replaceException(
                $staff,
                'work',
                $oldFrom,
                $oldTo,
                $newFrom,
                $newTo,
                $queue['queue_uuid'] ?? null,
                $queue['template'] ?? null,
            );
        } catch (Throwable $exception) {
            Notification::make()
                ->title($exception->getMessage() ?: 'Не удалось изменить период')
                ->danger()
                ->send();

            return false;
        }

        if (!$updated) {
            Notification::make()
                ->title('Период уже не найден')
                ->warning()
                ->send();

            return false;
        }

        $this->refreshRecords();

        Notification::make()
            ->title('Период изменен')
            ->success()
            ->send();

        return true;
    }

    private function staffFromResourceId(mixed $resourceId): ?Staff
    {
        if (!$resourceId) {
            return null;
        }

        return $this->selectedStaffQuery()
            ->find($resourceId);
    }

    private function currentScheduleQueue(): ?string
    {
        $options = $this->queueOptions();
        $queryQueue = request()->query('queue');

        if (is_string($queryQueue) && array_key_exists($queryQueue, $options)) {
            $this->scheduleQueue = $queryQueue;
            session([$this->scheduleQueueSessionKey() => $queryQueue]);

            return $this->scheduleQueue;
        }

        if (filled($this->scheduleQueue) && array_key_exists($this->scheduleQueue, $options)) {
            return $this->scheduleQueue;
        }

        $sessionQueue = session($this->scheduleQueueSessionKey());

        if (is_string($sessionQueue) && array_key_exists($sessionQueue, $options)) {
            $this->scheduleQueue = $sessionQueue;

            return $this->scheduleQueue;
        }

        $this->scheduleQueue = array_key_first($options);

        return $this->scheduleQueue;
    }

    private function scheduleQueueSessionKey(): string
    {
        return 'distribution.schedule.queue.' . (string)Auth::id();
    }

    /**
     * @return array{key: string|null, label: string, queue_uuid: string|null, template: int|null, staffs: array<int, int>}
     */
    private function selectedQueue(): array
    {
        $key = $this->currentScheduleQueue();
        $queues = $this->distributionQueues();

        return $key !== null && isset($queues[$key])
            ? $queues[$key]
            : [
                'key' => null,
                'label' => 'Очередь не выбрана',
                'queue_uuid' => null,
                'template' => null,
                'staffs' => [],
            ];
    }

    private function selectedQueueLabel(): string
    {
        return $this->selectedQueue()['label'];
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
     * @return array<string, array{key: string, label: string, queue_uuid: string|null, template: int|null, staffs: array<int, int>}>
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
                'queue_uuid' => $queueUuid,
                'template' => (int)$index,
                'staffs' => array_values(array_filter(array_map('intval', (array)($queue['staffs'] ?? [])))),
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

    private function currentDateSelectInfo(): ?DateSelectInfo
    {
        $info = $this->getCalendarContextInfo();

        return $info instanceof DateSelectInfo ? $info : null;
    }

    private function scheduleFormSchema(): array
    {
        return [
            Select::make('staff_id')
                ->label('Сотрудник')
                ->options(fn(): array => $this->staffOptions())
                ->searchable()
                ->required()
                ->native(false)
                ->live()
                ->afterStateUpdated(function (mixed $state, callable $set): void {
                    foreach ($this->scheduleActionDataForStaff((int)$state) as $key => $value) {
                        if ($key === 'staff_id') {
                            continue;
                        }

                        $set($key, $value);
                    }
                }),

            Section::make('Быстрая настройка')
                ->schema([
                    Select::make('quick_preset')
                        ->label('Шаблон')
                        ->options([
                            'always' => 'Всегда работает',
                            'cycle_2_2' => '2/2',
                            'cycle_3_3' => '3/3',
                            'weekdays_5_2' => '5/2 (пн-пт)',
                        ])
                        ->default('cycle_2_2')
                        ->required()
                        ->native(false)
                        ->live(),

                    Select::make('timezone')
                        ->label('Часовой пояс')
                        ->options(fn(): array => $this->scheduleSettings()->timezoneOptions())
                        ->default(config('app.timezone') ?: 'Europe/Moscow')
                        ->required()
                        ->searchable()
                        ->native(false),

                    TimePicker::make('quick_from')
                        ->label('Начало смены')
                        ->seconds(false)
                        ->native(false)
                        ->visible(
                            fn(callable $get): bool => !$get('advanced_mode') && $get('quick_preset') !== 'always'
                        )
                        ->required(
                            fn(callable $get): bool => !$get('advanced_mode') && $get('quick_preset') !== 'always'
                        ),

                    TimePicker::make('quick_to')
                        ->label('Конец смены')
                        ->seconds(false)
                        ->native(false)
                        ->visible(
                            fn(callable $get): bool => !$get('advanced_mode') && $get('quick_preset') !== 'always'
                        )
                        ->required(
                            fn(callable $get): bool => !$get('advanced_mode') && $get('quick_preset') !== 'always'
                        ),

                    DatePicker::make('quick_anchor_date')
                        ->label('Старт цикла')
                        ->native(false)
                        ->visible(
                            fn(callable $get): bool => !$get('advanced_mode')
                                && in_array($get('quick_preset'), ['cycle_2_2', 'cycle_3_3'], true)
                        )
                        ->required(
                            fn(callable $get): bool => !$get('advanced_mode')
                                && in_array($get('quick_preset'), ['cycle_2_2', 'cycle_3_3'], true)
                        ),

                    Toggle::make('advanced_mode')
                        ->label('Расширенный режим')
                        ->default(false)
                        ->live(),
                ])
                ->columns(2),

            Section::make('Расширенный график')
                ->visible(fn(callable $get): bool => (bool)$get('advanced_mode'))
                ->schema([
                    Select::make('mode')
                        ->label('Режим')
                        ->options([
                            'always' => 'Всегда работает',
                            'weekly' => 'По неделе',
                            'cycle' => 'Цикл',
                        ])
                        ->default('weekly')
                        ->required()
                        ->native(false)
                        ->live(),

                    Repeater::make('weekly_rules')
                        ->label('Правила недели')
                        ->visible(fn(callable $get): bool => $get('mode') === 'weekly')
                        ->schema([
                            Select::make('day')
                                ->label('День')
                                ->options([
                                    1 => 'Понедельник',
                                    2 => 'Вторник',
                                    3 => 'Среда',
                                    4 => 'Четверг',
                                    5 => 'Пятница',
                                    6 => 'Суббота',
                                    7 => 'Воскресенье',
                                ])
                                ->required(),

                            TimePicker::make('from')
                                ->label('Начало')
                                ->seconds(false)
                                ->native(false)
                                ->required(),

                            TimePicker::make('to')
                                ->label('Конец')
                                ->seconds(false)
                                ->native(false)
                                ->required(),
                        ])
                        ->columns()
                        ->collapsible()
                        ->defaultItems(1)
                        ->reorderable(false)
                        ->reorderableWithDragAndDrop(false)
                        ->addActionLabel('+ Добавить правило дня'),

                    Section::make('Цикл')
                        ->visible(fn(callable $get): bool => $get('mode') === 'cycle')
                        ->schema([
                            DatePicker::make('cycle_anchor_date')
                                ->label('Старт цикла')
                                ->native(false)
                                ->required(),

                            TimePicker::make('cycle_from')
                                ->label('Начало смены')
                                ->seconds(false)
                                ->native(false)
                                ->required(),

                            TimePicker::make('cycle_to')
                                ->label('Конец смены')
                                ->seconds(false)
                                ->native(false)
                                ->required(),

                            Select::make('cycle_work_days')
                                ->label('Рабочих дней')
                                ->options(array_combine(range(1, 14), range(1, 14)))
                                ->required(),

                            Select::make('cycle_rest_days')
                                ->label('Выходных дней')
                                ->options(array_combine(range(1, 14), range(1, 14)))
                                ->required(),
                        ])
                        ->columns(2),
                ]),

            Section::make('Исключения')
                ->schema([
                    Repeater::make('exceptions')
                        ->hiddenLabel()
                        ->schema([
                            DateTimePicker::make('from')
                                ->label('С')
                                ->seconds(false)
                                ->native(false)
                                ->required(),

                            DateTimePicker::make('to')
                                ->label('По')
                                ->seconds(false)
                                ->native(false)
                                ->required(),

                            Radio::make('type')
                                ->label('Правило')
                                ->options([
                                    'free' => 'Не работает',
                                    'work' => 'Работает',
                                ])
                                ->required(),
                        ])
                        ->columns()
                        ->collapsible()
                        ->defaultItems(0)
                        ->reorderable(false)
                        ->reorderableWithDragAndDrop(false)
                        ->addActionLabel('+ Добавить исключение'),
                ]),
        ];
    }

    protected function getResources(): Collection | array | Builder
    {
        return $this->staffCollection()
            ->map(fn(Staff $staff): CalendarResource => CalendarResource::make($staff->id)
                ->title($this->staffResourceTitle($staff)))
            ->values()
            ->all();
    }

    protected function eventContent(): string
    {
        return '<span class="distribution-schedule-event-fill" aria-hidden="true"></span>';
    }

    private function staffResourceTitle(Staff $staff): string
    {
        return $staff->name ?: ('Сотрудник #' . $staff->staff_id);
    }

    private function staffCollection(): Collection
    {
        return $this->selectedStaffQuery()
            ->orderBy('group_name')
            ->orderBy('name')
            ->get();
    }

    private function staffGroupName(Staff $staff): string
    {
        $groupName = trim((string)$staff->group_name);

        return $groupName !== '' ? $groupName : 'Без отдела';
    }

    protected function getEvents(FetchInfo $info): Collection | array | Builder
    {
        $rangeStart = Carbon::parse($info->start);
        $rangeEnd = Carbon::parse($info->end);

        return $this->staffCollection()
            ->flatMap(fn(Staff $staff): array => $this->eventsForStaff($staff, $rangeStart, $rangeEnd))
            ->values()
            ->all();
    }

    private function defaultScheduleActionData(): array
    {
        $staff = $this->selectedStaffQuery()
            ->orderBy('group_name')
            ->orderBy('name')
            ->first();

        return $this->scheduleActionDataForStaff($staff?->id);
    }

    private function scheduleActionDataForStaff(?int $staffId): array
    {
        $staff = $staffId
            ? $this->selectedStaffQuery()->find($staffId)
            : null;

        return [
            'staff_id' => $staff?->id,
            ...$this->scheduleSettings()->prepareFormData($staff ? $this->selectedScheduleSettings($staff) : null),
        ];
    }

    private function staffOptions(): array
    {
        return $this->staffCollection()
            ->mapWithKeys(fn(Staff $staff): array => [
                $staff->id => "{$this->staffGroupName($staff)} · {$staff->name}",
            ])
            ->all();
    }

    private function activeStaffQuery(): Builder
    {
        return Staff::query()
            ->where('user_id', Auth::id())
            ->where('active', true);
    }

    private function selectedStaffQuery(): Builder
    {
        $staffIds = $this->selectedQueue()['staffs'];

        return $this->activeStaffQuery()
            ->when(
                $staffIds !== [],
                fn(Builder $query): Builder => $query->whereIn('staff_id', $staffIds),
                fn(Builder $query): Builder => $query->whereRaw('1 = 0'),
            );
    }

    private function scheduleSettings(): ScheduleSettingsService
    {
        return app(ScheduleSettingsService::class);
    }

    private function selectedScheduleSettings(Staff $staff): ?string
    {
        $queue = $this->selectedQueue();

        return $this->scheduleSettings()->settingsForStaff(
            $staff,
            $queue['queue_uuid'] ?? null,
            $queue['template'] ?? null,
        );
    }

    private function eventsForStaff(Staff $staff, CarbonInterface $rangeStart, CarbonInterface $rangeEnd): array
    {
        $settings = $this->scheduleSettings()->decodeSettings($this->selectedScheduleSettings($staff));
        if ($settings === []) {
            return [];
        }

        $timezone = $this->scheduleSettings()->timezone($settings['timezone'] ?? null);
        $start = Carbon::parse($rangeStart)->timezone($timezone);
        $end = Carbon::parse($rangeEnd)->timezone($timezone);

        $baseEvents = $this->baseScheduleEvents($staff, $settings, $start, $end, $timezone);

        return [
            ...$this->subtractFreeExceptions(
                $staff,
                $baseEvents,
                $this->exceptionRanges($settings, $timezone, 'free'),
                $timezone,
            ),
            ...$this->workExceptionEvents($staff, $settings, $timezone),
        ];
    }

    private function baseScheduleEvents(
        Staff $staff,
        array $settings,
        CarbonInterface $rangeStart,
        CarbonInterface $rangeEnd,
        string $timezone
    ): array {
        return match ($settings['mode'] ?? 'weekly') {
            'always' => [
                $this->event($staff, 'Работает', $rangeStart, $rangeEnd, $timezone, self::COLOR_PRIMARY),
            ],
            'cycle' => $this->cycleEvents($staff, $settings['cycle'] ?? [], $rangeStart, $rangeEnd, $timezone),
            default => $this->weeklyEvents($staff, $settings['weekly_rules'] ?? [], $rangeStart, $rangeEnd, $timezone),
        };
    }

    private function weeklyEvents(
        Staff $staff,
        array $rules,
        CarbonInterface $rangeStart,
        CarbonInterface $rangeEnd,
        string $timezone
    ): array {
        $events = [];
        $cursor = Carbon::parse($rangeStart)->timezone($timezone)->startOfDay();
        $limit = Carbon::parse($rangeEnd)->timezone($timezone)->endOfDay();

        while ($cursor->lte($limit)) {
            foreach ($rules as $rule) {
                if (!is_array($rule) || (int)($rule['day'] ?? 0) !== (int)$cursor->dayOfWeekIso) {
                    continue;
                }

                $events = [
                    ...$events,
                    ...$this->eventsFromTimeRange($staff, 'Смена', $cursor, $rule, $timezone, self::COLOR_PRIMARY),
                ];
            }

            $cursor->addDay();
        }

        return $events;
    }

    private function cycleEvents(
        Staff $staff,
        array $cycle,
        CarbonInterface $rangeStart,
        CarbonInterface $rangeEnd,
        string $timezone
    ): array {
        $anchorDate = $cycle['anchor_date'] ?? null;
        if (!$anchorDate) {
            return [];
        }

        $anchor = Carbon::parse($anchorDate, $timezone)->startOfDay();
        $workDays = max(1, (int)($cycle['work_days'] ?? 1));
        $restDays = max(1, (int)($cycle['rest_days'] ?? 1));
        $totalDays = $workDays + $restDays;
        $events = [];
        $cursor = Carbon::parse($rangeStart)->timezone($timezone)->startOfDay();
        $limit = Carbon::parse($rangeEnd)->timezone($timezone)->endOfDay();

        while ($cursor->lte($limit)) {
            $delta = $anchor->diffInDays($cursor, false);
            $position = (($delta % $totalDays) + $totalDays) % $totalDays;

            if ($position < $workDays) {
                $events = [
                    ...$events,
                    ...$this->eventsFromTimeRange($staff, 'Смена', $cursor, $cycle, $timezone, self::COLOR_PRIMARY),
                ];
            }

            $cursor->addDay();
        }

        return $events;
    }

    private function workExceptionEvents(Staff $staff, array $settings, string $timezone): array
    {
        return collect($this->scheduleSettings()->normalizeExceptions($settings['exceptions'] ?? []))
            ->filter(fn(array $exception): bool => ($exception['type'] ?? null) === 'work')
            ->map(function (array $exception) use ($staff, $timezone): CalendarEvent {
                $from = Carbon::parse($exception['from'], $timezone);
                $to = Carbon::parse($exception['to'], $timezone);

                return $this->event(
                    $staff,
                    'Работает',
                    $from,
                    $to,
                    $timezone,
                    self::COLOR_PRIMARY_DARK,
                    [
                        'distribution_schedule_kind' => 'work_exception',
                        'distribution_exception_from' => $from->format('Y-m-d H:i:s'),
                        'distribution_exception_to' => $to->format('Y-m-d H:i:s'),
                    ],
                );
            })
            ->all();
    }

    private function subtractFreeExceptions(Staff $staff, array $events, array $freeRanges, string $timezone): array
    {
        if ($freeRanges === []) {
            return $events;
        }

        $result = [];

        foreach ($events as $event) {
            if (!$event instanceof CalendarEvent) {
                continue;
            }

            $segments = [[
                'start' => Carbon::parse($event->getStart())->timezone($timezone),
                'end' => Carbon::parse($event->getEnd())->timezone($timezone),
            ]];

            foreach ($freeRanges as $freeRange) {
                $segments = $this->subtractRangeFromSegments($segments, $freeRange['start'], $freeRange['end']);
            }

            foreach ($segments as $segment) {
                if ($segment['start']->lt($segment['end'])) {
                    $result[] = $this->event(
                        $staff,
                        (string)$event->getTitle(),
                        $segment['start'],
                        $segment['end'],
                        $timezone,
                        $event->getBackgroundColor() ?? self::COLOR_PRIMARY,
                    );
                }
            }
        }

        return $result;
    }

    private function subtractRangeFromSegments(array $segments, CarbonInterface $freeStart, CarbonInterface $freeEnd): array
    {
        $result = [];

        foreach ($segments as $segment) {
            $segmentStart = Carbon::parse($segment['start']);
            $segmentEnd = Carbon::parse($segment['end']);

            if ($freeEnd->lte($segmentStart) || $freeStart->gte($segmentEnd)) {
                $result[] = $segment;
                continue;
            }

            if ($freeStart->gt($segmentStart)) {
                $result[] = [
                    'start' => $segmentStart,
                    'end' => Carbon::parse($freeStart)->min($segmentEnd),
                ];
            }

            if ($freeEnd->lt($segmentEnd)) {
                $result[] = [
                    'start' => Carbon::parse($freeEnd)->max($segmentStart),
                    'end' => $segmentEnd,
                ];
            }
        }

        return $result;
    }

    private function exceptionRanges(array $settings, string $timezone, string $type): array
    {
        return collect($this->scheduleSettings()->normalizeExceptions($settings['exceptions'] ?? []))
            ->filter(fn(array $exception): bool => ($exception['type'] ?? null) === $type)
            ->map(fn(array $exception): array => [
                'start' => Carbon::parse($exception['from'], $timezone),
                'end' => Carbon::parse($exception['to'], $timezone),
            ])
            ->values()
            ->all();
    }

    private function eventsFromTimeRange(
        Staff $staff,
        string $title,
        CarbonInterface $date,
        array $range,
        string $timezone,
        string $color
    ): array {
        $from = $range['from'] ?? null;
        $to = $range['to'] ?? null;

        if (!$from || !$to) {
            return [
                $this->event(
                    $staff,
                    $title,
                    Carbon::parse($date)->timezone($timezone)->startOfDay(),
                    Carbon::parse($date)->timezone($timezone)->endOfDay(),
                    $timezone,
                    $color
                ),
            ];
        }

        $start = Carbon::parse($date->format('Y-m-d') . ' ' . $from, $timezone);
        $end = Carbon::parse($date->format('Y-m-d') . ' ' . $to, $timezone);

        if ($end->lte($start)) {
            $end->addDay();
        }

        return [
            $this->event($staff, $title, $start, $end, $timezone, $color),
        ];
    }

    private function event(
        Staff $staff,
        string $title,
        CarbonInterface $start,
        CarbonInterface $end,
        string $timezone,
        string $color,
        array $extendedProps = []
    ): CalendarEvent {
        $extendedProps = [
            'distribution_schedule_kind' => 'base',
            ...$extendedProps,
        ];
        $editable = ($extendedProps['distribution_schedule_kind'] ?? null) === 'work_exception';

        return CalendarEvent::make($staff)
            ->title($title)
            ->start(Carbon::parse($start)->timezone($timezone))
            ->end(Carbon::parse($end)->timezone($timezone))
            ->timezone($timezone)
            ->resourceId($staff->id)
            ->extendedProps($extendedProps)
            ->backgroundColor($color)
            ->textColor(self::COLOR_TEXT_ON_PRIMARY)
            ->action('deleteSchedulePeriod')
            ->classes(['distribution-schedule-event'])
            ->styles([
                'border-color' => $color,
                'cursor' => 'pointer',
                'box-shadow' => '0 1px 2px rgb(15 15 15 / 0.08)',
            ])
            ->editable($editable)
            ->startEditable($editable)
            ->durationEditable($editable);
    }
}

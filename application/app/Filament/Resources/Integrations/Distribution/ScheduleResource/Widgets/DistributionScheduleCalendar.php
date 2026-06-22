<?php

namespace App\Filament\Resources\Integrations\Distribution\ScheduleResource\Widgets;

use App\Models\amoCRM\Staff;
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
use Guava\Calendar\ValueObjects\FetchInfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class DistributionScheduleCalendar extends CalendarWidget
{
    private const COLOR_PRIMARY = '#ff6a00';
    private const COLOR_PRIMARY_DARK = '#cf5200';
    private const COLOR_MUTED_DARK = '#51463d';
    private const COLOR_TEXT_ON_PRIMARY = '#ffffff';

    protected HtmlString | string | bool | null $heading = false;

    protected CalendarViewType $calendarView = CalendarViewType::ResourceTimelineWeek;

    protected bool $dateSelectEnabled = true;

    protected array $options = [
        'height' => 'auto',
        'nowIndicator' => true,
        'slotMinWidth' => 72,
        'resourceAreaWidth' => '360px',
        'eventMinWidth' => 16,
        'headerToolbar' => [
            'start' => 'prev,next today',
            'center' => 'title',
            'end' => 'resourceTimelineWeek,resourceTimelineMonth',
        ],
        'buttonText' => [
            'today' => 'Сегодня',
            'resourceTimelineWeek' => 'Неделя',
            'resourceTimelineMonth' => 'Месяц',
        ],
    ];

    public function getHeaderActions(): array
    {
        return [];
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
                $staff = $this->activeStaffQuery()->find($data['staff_id'] ?? null);

                if (!$staff) {
                    Notification::make()
                        ->title('Выберите сотрудника')
                        ->danger()
                        ->send();

                    return;
                }

                $payload = $this->scheduleSettings()->buildPayload($data);
                $this->scheduleSettings()->saveForStaff($staff, $payload);

                $this->refreshRecords();

                Notification::make()
                    ->title('График сохранен')
                    ->success()
                    ->send();
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

                $staff = Staff::query()
                    ->where('user_id', Auth::id())
                    ->where('active', true)
                    ->find($dateSelect->resource->getId());

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

                $this->scheduleSettings()->addException($staff, [
                    'type' => 'work',
                    'from' => $from->format('Y-m-d H:i:s'),
                    'to' => $to->format('Y-m-d H:i:s'),
                ]);

                $this->refreshRecords();

                Notification::make()
                    ->title('Исключение добавлено')
                    ->success()
                    ->send();
            });
    }

    protected function onDateSelect(DateSelectInfo $info): void
    {
        $this->mountAction('createException');
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

    private function staffResourceTitle(Staff $staff): string
    {
        return $staff->name ?: ('Сотрудник #' . $staff->staff_id);
    }

    private function staffCollection(): Collection
    {
        return $this->activeStaffQuery()
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

        return $this->activeStaffQuery()
            ->with('schedule')
            ->get()
            ->flatMap(fn(Staff $staff): array => $this->eventsForStaff($staff, $rangeStart, $rangeEnd))
            ->values()
            ->all();
    }

    private function defaultScheduleActionData(): array
    {
        $staff = $this->activeStaffQuery()
            ->with('schedule')
            ->orderBy('group_name')
            ->orderBy('name')
            ->first();

        return $this->scheduleActionDataForStaff($staff?->id);
    }

    private function scheduleActionDataForStaff(?int $staffId): array
    {
        $staff = $staffId
            ? $this->activeStaffQuery()->with('schedule')->find($staffId)
            : null;

        return [
            'staff_id' => $staff?->id,
            ...$this->scheduleSettings()->prepareFormData($staff?->schedule?->settings),
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

    private function scheduleSettings(): ScheduleSettingsService
    {
        return app(ScheduleSettingsService::class);
    }

    private function eventsForStaff(Staff $staff, CarbonInterface $rangeStart, CarbonInterface $rangeEnd): array
    {
        $settings = $this->scheduleSettings()->decodeSettings($staff->schedule?->settings);
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
                return $this->event(
                    $staff,
                    'Работает',
                    Carbon::parse($exception['from'], $timezone),
                    Carbon::parse($exception['to'], $timezone),
                    $timezone,
                    self::COLOR_PRIMARY_DARK
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
        string $color
    ): CalendarEvent {
        return CalendarEvent::make()
            ->title($title)
            ->start(Carbon::parse($start)->timezone($timezone))
            ->end(Carbon::parse($end)->timezone($timezone))
            ->timezone($timezone)
            ->resourceId($staff->id)
            ->backgroundColor($color)
            ->textColor(self::COLOR_TEXT_ON_PRIMARY)
            ->styles([
                'border-color' => $color,
                'box-shadow' => '0 1px 2px rgb(15 15 15 / 0.08)',
            ])
            ->editable(false);
    }
}

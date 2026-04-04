<?php

namespace App\Filament\Resources\Integrations\Distribution\ScheduleResource\Pages;

use App\Filament\Resources\Integrations\Distribution\ScheduleResource;
use App\Filament\Resources\Integrations\DistributionResource;
use App\Models\amoCRM\Staff;
use App\Models\Integrations\Distribution\Scheduler;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ListSchedule extends ListRecords
{
    protected static string $resource = ScheduleResource::class;

    protected function getTableQuery(): ?Builder
    {
        $query = Staff::query();

        $query
            ->where('user_id', Auth::user()->id)
            ->where('active', true);

        return $query;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('staff_id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Имя')
                    ->searchable(),

                TextColumn::make('group_name')
                    ->label('Отдел')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('login')
                    ->label('Почта')
                    ->wrap()
                    ->searchable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([])
            ->actions([
                Actions\Action::make('scheduleSave')
                    ->label('График')
                    ->form([
                        Section::make('Настройки графика')
                            ->description('Используйте режим работы и только нужные исключения')
                            ->schema([
                                Select::make('quick_preset')
                                    ->label('Быстрый шаблон')
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
                                    ->options($this->timezoneOptions())
                                    ->default(config('app.timezone') ?: 'Europe/Moscow')
                                    ->required()
                                    ->searchable()
                                    ->native(false),

                                TimePicker::make('quick_from')
                                    ->label('Начало смены')
                                    ->seconds(false)
                                    ->native(false)
                                    ->visible(
                                        fn(callable $get): bool => !$get('advanced_mode') && $get(
                                                'quick_preset'
                                            ) !== 'always'
                                    )
                                    ->required(
                                        fn(callable $get): bool => !$get('advanced_mode') && $get(
                                                'quick_preset'
                                            ) !== 'always'
                                    ),

                                TimePicker::make('quick_to')
                                    ->label('Конец смены')
                                    ->seconds(false)
                                    ->native(false)
                                    ->visible(
                                        fn(callable $get): bool => !$get('advanced_mode') && $get(
                                                'quick_preset'
                                            ) !== 'always'
                                    )
                                    ->required(
                                        fn(callable $get): bool => !$get('advanced_mode') && $get(
                                                'quick_preset'
                                            ) !== 'always'
                                    ),

                                DatePicker::make('quick_anchor_date')
                                    ->label('Старт цикла')
                                    ->native(false)
                                    ->visible(
                                        fn(callable $get): bool => !$get('advanced_mode') && in_array(
                                                $get('quick_preset'),
                                                ['cycle_2_2', 'cycle_3_3'],
                                                true
                                            )
                                    )
                                    ->required(
                                        fn(callable $get): bool => !$get('advanced_mode') && in_array(
                                                $get('quick_preset'),
                                                ['cycle_2_2', 'cycle_3_3'],
                                                true
                                            )
                                    ),

                                Toggle::make('advanced_mode')
                                    ->label('Расширенный режим')
                                    ->default(false)
                                    ->live(),

                                Select::make('mode')
                                    ->label('Режим')
                                    ->visible(fn(callable $get): bool => (bool)$get('advanced_mode'))
                                    ->options([
                                        'always' => 'Всегда работает',
                                        'weekly' => 'По неделе',
                                        'cycle' => 'Цикл (2/2, 3/3 и т.д.)',
                                    ])
                                    ->default('weekly')
                                    ->required(fn(callable $get): bool => (bool)$get('advanced_mode'))
                                    ->native(false)
                                    ->live(),

                                Repeater::make('weekly_rules')
                                    ->label('Правила недели')
                                    ->visible(
                                        fn(callable $get): bool => (bool)$get('advanced_mode') && $get(
                                                'mode'
                                            ) === 'weekly'
                                    )
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

                                Section::make('Цикличный график')
                                    ->visible(
                                        fn(callable $get): bool => (bool)$get('advanced_mode') && $get(
                                                'mode'
                                            ) === 'cycle'
                                    )
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

                                Repeater::make('exceptions')
                                    ->label('Исключения')
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
                    ])
                    ->fillForm(function (Staff $staff): array {
                        return $this->prepareScheduleFormData($staff->schedule->settings ?? null);
                    })
                    ->action(function ($data, $record): void {
                        $payload = $this->buildSchedulePayload($data);
                        $this->validateSchedulePayload($payload);

                        Scheduler::query()->updateOrCreate([
                            'staff_id' => $record->id,
                        ], [
                            'settings' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                            'user_id' => $record->user_id,
                        ]);
                    })
            ])
            ->paginated([20, 30, 'all'])
            ->poll('30s')
            ->emptyStateActions([]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('settings')
                ->label('Настройки')
                ->icon('heroicon-o-cog-6-tooth')
                ->url(DistributionResource::getUrl('edit', ['record' => Auth::user()->distribution_settings->id])),
        ];
    }

    private function prepareScheduleFormData(?string $settingsJson): array
    {
        $decoded = json_decode($settingsJson ?? '[]', true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        $allowedModes = ['always', 'weekly', 'cycle'];
        $mode = $decoded['mode'] ?? 'weekly';
        if (!in_array($mode, $allowedModes, true)) {
            $mode = 'weekly';
        }

        $quick = $this->resolveQuickState($decoded);

        return [
            'quick_preset' => $quick['preset'],
            'quick_from' => $quick['from'],
            'quick_to' => $quick['to'],
            'quick_anchor_date' => $quick['anchor_date'],
            'timezone' => $decoded['timezone'] ?? (config('app.timezone') ?: 'Europe/Moscow'),
            'advanced_mode' => $quick['advanced_mode'],
            'mode' => $mode,
            'weekly_rules' => array_values($decoded['weekly_rules'] ?? []),
            'cycle_anchor_date' => $decoded['cycle']['anchor_date'] ?? null,
            'cycle_from' => $decoded['cycle']['from'] ?? null,
            'cycle_to' => $decoded['cycle']['to'] ?? null,
            'cycle_work_days' => $decoded['cycle']['work_days'] ?? null,
            'cycle_rest_days' => $decoded['cycle']['rest_days'] ?? null,
            'exceptions' => array_values($decoded['exceptions'] ?? []),
        ];
    }

    private function buildSchedulePayload(array $data): array
    {
        if (!(bool)($data['advanced_mode'] ?? false)) {
            return $this->buildQuickPayload($data);
        }

        $mode = in_array(($data['mode'] ?? ''), ['always', 'weekly', 'cycle'], true)
            ? $data['mode']
            : 'weekly';

        $payload = [
            'mode' => $mode,
            'timezone' => (string)($data['timezone'] ?? (config('app.timezone') ?: 'Europe/Moscow')),
            'exceptions' => $this->normalizeDateTimeRanges($data['exceptions'] ?? []),
        ];

        if ($mode === 'always') {
            return $payload;
        }

        if ($mode === 'weekly') {
            $payload['weekly_rules'] = array_values(array_filter(array_map(function (array $rule): ?array {
                $day = isset($rule['day']) ? (int)$rule['day'] : 0;
                $from = $rule['from'] ?? null;
                $to = $rule['to'] ?? null;

                if ($day < 1 || $day > 7 || !$from || !$to) {
                    return null;
                }

                return [
                    'day' => $day,
                    'from' => Carbon::parse($from)->format('H:i:s'),
                    'to' => Carbon::parse($to)->format('H:i:s'),
                ];
            }, $data['weekly_rules'] ?? [])));

            return $payload;
        }

        if ($mode === 'cycle') {
            $anchorDate = $data['cycle_anchor_date'] ?? null;
            $from = $data['cycle_from'] ?? null;
            $to = $data['cycle_to'] ?? null;

            $payload['cycle'] = [
                'anchor_date' => $anchorDate ? Carbon::parse($anchorDate)->format('Y-m-d') : null,
                'from' => $from ? Carbon::parse($from)->format('H:i:s') : null,
                'to' => $to ? Carbon::parse($to)->format('H:i:s') : null,
                'work_days' => max(1, (int)($data['cycle_work_days'] ?? 2)),
                'rest_days' => max(1, (int)($data['cycle_rest_days'] ?? 2)),
            ];

            return $payload;
        }

        return $payload;
    }

    private function buildQuickPayload(array $data): array
    {
        $preset = $data['quick_preset'] ?? 'cycle_2_2';
        $from = $data['quick_from'] ?? '09:00:00';
        $to = $data['quick_to'] ?? '18:00:00';
        $anchorDate = $data['quick_anchor_date'] ?? Carbon::now()->toDateString();

        $payload = [
            'mode' => 'cycle',
            'timezone' => (string)($data['timezone'] ?? (config('app.timezone') ?: 'Europe/Moscow')),
            'exceptions' => $this->normalizeDateTimeRanges($data['exceptions'] ?? []),
        ];

        if ($preset === 'always') {
            $payload['mode'] = 'always';

            return $payload;
        }

        if ($preset === 'weekdays_5_2') {
            $payload['mode'] = 'weekly';
            $payload['weekly_rules'] = array_map(function (int $day) use ($from, $to): array {
                return [
                    'day' => $day,
                    'from' => Carbon::parse($from)->format('H:i:s'),
                    'to' => Carbon::parse($to)->format('H:i:s'),
                ];
            }, [1, 2, 3, 4, 5]);

            return $payload;
        }

        $workDays = $preset === 'cycle_3_3' ? 3 : 2;
        $restDays = $preset === 'cycle_3_3' ? 3 : 2;

        $payload['mode'] = 'cycle';
        $payload['cycle'] = [
            'anchor_date' => Carbon::parse($anchorDate)->format('Y-m-d'),
            'from' => Carbon::parse($from)->format('H:i:s'),
            'to' => Carbon::parse($to)->format('H:i:s'),
            'work_days' => $workDays,
            'rest_days' => $restDays,
        ];

        return $payload;
    }

    private function resolveQuickState(array $decoded): array
    {
        $state = [
            'preset' => 'cycle_2_2',
            'from' => '09:00:00',
            'to' => '18:00:00',
            'anchor_date' => Carbon::now()->toDateString(),
            'advanced_mode' => false,
        ];

        $mode = $decoded['mode'] ?? null;
        if ($mode === 'always') {
            $state['preset'] = 'always';

            return $state;
        }

        if ($mode === 'cycle') {
            $workDays = (int)($decoded['cycle']['work_days'] ?? 0);
            $restDays = (int)($decoded['cycle']['rest_days'] ?? 0);
            $state['from'] = $decoded['cycle']['from'] ?? $state['from'];
            $state['to'] = $decoded['cycle']['to'] ?? $state['to'];
            $state['anchor_date'] = $decoded['cycle']['anchor_date'] ?? $state['anchor_date'];

            if ($workDays === 2 && $restDays === 2) {
                $state['preset'] = 'cycle_2_2';

                return $state;
            }

            if ($workDays === 3 && $restDays === 3) {
                $state['preset'] = 'cycle_3_3';

                return $state;
            }

            $state['advanced_mode'] = true;

            return $state;
        }

        if ($mode === 'weekly' && $this->isWeekdaysPreset($decoded['weekly_rules'] ?? [])) {
            $state['preset'] = 'weekdays_5_2';
            $firstRule = $decoded['weekly_rules'][0] ?? [];
            $state['from'] = $firstRule['from'] ?? $state['from'];
            $state['to'] = $firstRule['to'] ?? $state['to'];

            return $state;
        }

        if ($mode !== null) {
            $state['advanced_mode'] = true;
        }

        return $state;
    }

    private function isWeekdaysPreset(array $rules): bool
    {
        if (count($rules) !== 5) {
            return false;
        }

        $byDay = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                return false;
            }

            $day = (int)($rule['day'] ?? 0);
            if ($day < 1 || $day > 5) {
                return false;
            }

            $byDay[$day] = $rule;
        }

        if (count($byDay) !== 5) {
            return false;
        }

        $first = reset($byDay);
        $from = $first['from'] ?? null;
        $to = $first['to'] ?? null;
        if (!$from || !$to) {
            return false;
        }

        foreach ([1, 2, 3, 4, 5] as $day) {
            if (($byDay[$day]['from'] ?? null) !== $from || ($byDay[$day]['to'] ?? null) !== $to) {
                return false;
            }
        }

        return true;
    }

    private function normalizeDateTimeRanges(array $items): array
    {
        return array_values(array_filter(array_map(function (array $item): ?array {
            $from = $item['from'] ?? null;
            $to = $item['to'] ?? null;
            $type = $item['type'] ?? null;

            if (!$from || !$to || !in_array($type, ['work', 'free'], true)) {
                return null;
            }

            return [
                'type' => $type,
                'from' => Carbon::parse($from)->format('Y-m-d H:i:s'),
                'to' => Carbon::parse($to)->format('Y-m-d H:i:s'),
            ];
        }, $items)));
    }

    private function validateSchedulePayload(array $payload): void
    {
        $errors = [];

        $timezone = (string)($payload['timezone'] ?? '');
        if (!$this->isValidTimezone($timezone)) {
            $errors['timezone'] = 'Укажите корректный часовой пояс.';
        }

        $mode = (string)($payload['mode'] ?? '');
        if (!in_array($mode, ['always', 'weekly', 'cycle'], true)) {
            $errors['mode'] = 'Некорректный режим графика.';
        }

        $this->validateDateTimeRanges(
            $payload['exceptions'] ?? [],
            'exceptions',
            $errors,
            'Исключения'
        );

        if ($mode === 'weekly') {
            $this->validateWeeklyRules($payload['weekly_rules'] ?? [], $errors);
        }

        if ($mode === 'cycle') {
            $this->validateCycleConfig($payload['cycle'] ?? [], $errors);
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function validateDateTimeRanges(array $ranges, string $key, array &$errors, string $label): void
    {
        $intervals = [];

        foreach ($ranges as $index => $range) {
            if (!is_array($range)) {
                $errors["{$key}.{$index}.from"] = "{$label}: неверный формат периода.";
                continue;
            }

            try {
                $from = Carbon::parse($range['from'] ?? null);
                $to = Carbon::parse($range['to'] ?? null);
            } catch (\Throwable) {
                $errors["{$key}.{$index}.from"] = "{$label}: неверный формат даты/времени.";
                continue;
            }

            if ($from->gte($to)) {
                $errors["{$key}.{$index}.to"] = "{$label}: конец периода должен быть позже начала.";
                continue;
            }

            $intervals[] = [
                'index' => $index,
                'start' => $from->timestamp,
                'end' => $to->timestamp,
            ];
        }

        usort($intervals, fn(array $a, array $b): int => $a['start'] <=> $b['start']);

        for ($i = 1; $i < count($intervals); $i++) {
            $prev = $intervals[$i - 1];
            $curr = $intervals[$i];

            if ($curr['start'] < $prev['end']) {
                $errors["{$key}.{$curr['index']}.from"] = "{$label}: периоды не должны пересекаться.";
                $errors["{$key}.{$prev['index']}.from"] = "{$label}: периоды не должны пересекаться.";
            }
        }
    }

    private function validateWeeklyRules(array $rules, array &$errors): void
    {
        if (count($rules) === 0) {
            $errors['weekly_rules'] = 'Добавьте хотя бы одно правило для режима "По неделе".';
            return;
        }

        $segments = [];
        $daySeconds = 86400;

        foreach ($rules as $index => $rule) {
            if (!is_array($rule)) {
                $errors["weekly_rules.{$index}.day"] = 'Неверный формат правила дня.';
                continue;
            }

            $day = (int)($rule['day'] ?? 0);
            if ($day < 1 || $day > 7) {
                $errors["weekly_rules.{$index}.day"] = 'День недели должен быть в диапазоне 1..7.';
                continue;
            }

            $fromSeconds = $this->timeToSeconds((string)($rule['from'] ?? ''));
            $toSeconds = $this->timeToSeconds((string)($rule['to'] ?? ''));

            if ($fromSeconds === null) {
                $errors["weekly_rules.{$index}.from"] = 'Некорректное время начала.';
                continue;
            }

            if ($toSeconds === null) {
                $errors["weekly_rules.{$index}.to"] = 'Некорректное время окончания.';
                continue;
            }

            if ($fromSeconds === $toSeconds) {
                $errors["weekly_rules.{$index}.to"] = 'Начало и конец смены не должны совпадать.';
                continue;
            }

            $dayStart = ($day - 1) * $daySeconds;

            if ($fromSeconds < $toSeconds) {
                $segments[] = [
                    'index' => $index,
                    'start' => $dayStart + $fromSeconds,
                    'end' => $dayStart + $toSeconds,
                ];
                continue;
            }

            $segments[] = [
                'index' => $index,
                'start' => $dayStart + $fromSeconds,
                'end' => $dayStart + $daySeconds,
            ];

            $nextDayStart = ($day % 7) * $daySeconds;
            $segments[] = [
                'index' => $index,
                'start' => $nextDayStart,
                'end' => $nextDayStart + $toSeconds,
            ];
        }

        usort($segments, fn(array $a, array $b): int => $a['start'] <=> $b['start']);

        for ($i = 1; $i < count($segments); $i++) {
            $prev = $segments[$i - 1];
            $curr = $segments[$i];

            if ($curr['start'] < $prev['end']) {
                $errors["weekly_rules.{$curr['index']}.from"] = 'Недельные интервалы не должны пересекаться.';
                $errors["weekly_rules.{$prev['index']}.from"] = 'Недельные интервалы не должны пересекаться.';
            }
        }
    }

    private function validateCycleConfig(array $cycle, array &$errors): void
    {
        $anchorDate = $cycle['anchor_date'] ?? null;
        if (!is_string($anchorDate) || trim($anchorDate) === '') {
            $errors['cycle.anchor_date'] = 'Укажите дату старта цикла.';
        } else {
            try {
                Carbon::parse($anchorDate);
            } catch (\Throwable) {
                $errors['cycle.anchor_date'] = 'Некорректная дата старта цикла.';
            }
        }

        $workDays = (int)($cycle['work_days'] ?? 0);
        if ($workDays < 1 || $workDays > 14) {
            $errors['cycle.work_days'] = 'Количество рабочих дней должно быть в диапазоне 1..14.';
        }

        $restDays = (int)($cycle['rest_days'] ?? 0);
        if ($restDays < 1 || $restDays > 14) {
            $errors['cycle.rest_days'] = 'Количество выходных дней должно быть в диапазоне 1..14.';
        }

        $fromSeconds = $this->timeToSeconds((string)($cycle['from'] ?? ''));
        if ($fromSeconds === null) {
            $errors['cycle.from'] = 'Некорректное время начала смены.';
        }

        $toSeconds = $this->timeToSeconds((string)($cycle['to'] ?? ''));
        if ($toSeconds === null) {
            $errors['cycle.to'] = 'Некорректное время окончания смены.';
        }

        if ($fromSeconds !== null && $toSeconds !== null && $fromSeconds === $toSeconds) {
            $errors['cycle.to'] = 'Начало и конец смены не должны совпадать.';
        }
    }

    private function isValidTimezone(string $timezone): bool
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }

    private function timeToSeconds(string $time): ?int
    {
        $parts = explode(':', $time);
        if (count($parts) < 2) {
            return null;
        }

        $hours = (int)$parts[0];
        $minutes = (int)$parts[1];
        $seconds = isset($parts[2]) ? (int)$parts[2] : 0;

        if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59 || $seconds < 0 || $seconds > 59) {
            return null;
        }

        return $hours * 3600 + $minutes * 60 + $seconds;
    }

    private function timezoneOptions(): array
    {
        static $options = null;

        if (is_array($options)) {
            return $options;
        }

        $zones = DateTimeZone::listIdentifiers();
        $options = array_combine($zones, $zones);

        return $options ?: ['Europe/Moscow' => 'Europe/Moscow'];
    }
}

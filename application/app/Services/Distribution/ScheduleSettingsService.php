<?php

namespace App\Services\Distribution;

use App\Models\amoCRM\Staff;
use App\Models\Integrations\Distribution\Scheduler;
use Carbon\Carbon;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

class ScheduleSettingsService
{
    public function prepareFormData(?string $settingsJson): array
    {
        $decoded = $this->decodeSettings($settingsJson);

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
            'exceptions' => $this->normalizeExceptions($decoded['exceptions'] ?? []),
        ];
    }

    public function buildPayload(array $data): array
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

    public function saveForStaff(Staff $staff, array $payload): Scheduler
    {
        $this->validatePayload($payload);

        return Scheduler::query()->updateOrCreate([
            'staff_id' => $staff->id,
        ], [
            'settings' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'user_id' => $staff->user_id,
        ]);
    }

    public function addException(Staff $staff, array $exception): Scheduler
    {
        $scheduler = Scheduler::query()->firstOrNew([
            'staff_id' => $staff->id,
        ]);

        $settings = $this->decodeSettings($scheduler->settings);
        $settings['timezone'] ??= config('app.timezone') ?: 'Europe/Moscow';
        $settings['mode'] ??= 'weekly';
        $settings['exceptions'] = $this->normalizeExceptions($settings['exceptions'] ?? []);

        $normalized = [
            'type' => in_array($exception['type'] ?? null, ['work', 'free'], true) ? $exception['type'] : 'work',
            'from' => Carbon::parse($exception['from'])->format('Y-m-d H:i:s'),
            'to' => Carbon::parse($exception['to'])->format('Y-m-d H:i:s'),
        ];

        $errors = [];
        $this->validateDateTimeRanges([$normalized], 'exception', $errors, 'Исключение');
        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        if ($this->overlapsExistingException($settings['exceptions'], $normalized)) {
            throw ValidationException::withMessages([
                'from' => 'Период пересекается с другим исключением.',
            ]);
        }

        $settings['exceptions'][] = $normalized;
        usort(
            $settings['exceptions'],
            fn(array $left, array $right): int => strcmp($left['from'], $right['from'])
        );

        $scheduler->fill([
            'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE),
            'user_id' => $staff->user_id,
        ])->save();

        return $scheduler;
    }

    public function decodeSettings(?string $settingsJson): array
    {
        $decoded = json_decode($settingsJson ?? '[]', true);

        return is_array($decoded) ? $decoded : [];
    }

    public function normalizeExceptions(array $exceptions): array
    {
        return array_values(array_filter(array_map(function (mixed $exception): ?array {
            if (!is_array($exception)) {
                return null;
            }

            if (!in_array($exception['type'] ?? null, ['work', 'free'], true)) {
                return null;
            }

            if (blank($exception['from'] ?? null) || blank($exception['to'] ?? null)) {
                return null;
            }

            return [
                'type' => $exception['type'],
                'from' => (string)$exception['from'],
                'to' => (string)$exception['to'],
            ];
        }, $exceptions)));
    }

    public function timezone(?string $timezone): string
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(), true)
            ? $timezone
            : (config('app.timezone') ?: 'Europe/Moscow');
    }

    public function timezoneOptions(): array
    {
        static $options = null;

        if (is_array($options)) {
            return $options;
        }

        $zones = DateTimeZone::listIdentifiers();
        $options = array_combine($zones, $zones);

        return $options ?: ['Europe/Moscow' => 'Europe/Moscow'];
    }

    public function validatePayload(array $payload): void
    {
        $errors = [];

        $timezone = (string)($payload['timezone'] ?? '');
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $errors['timezone'] = 'Укажите корректный часовой пояс.';
        }

        $mode = (string)($payload['mode'] ?? '');
        if (!in_array($mode, ['always', 'weekly', 'cycle'], true)) {
            $errors['mode'] = 'Некорректный режим графика.';
        }

        $this->validateDateTimeRanges($payload['exceptions'] ?? [], 'exceptions', $errors, 'Исключения');

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

    private function validateDateTimeRanges(array $ranges, string $key, ?array &$errors, string $label): void
    {
        $errors ??= [];
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

    private function overlapsExistingException(array $exceptions, array $candidate): bool
    {
        $candidateStart = Carbon::parse($candidate['from']);
        $candidateEnd = Carbon::parse($candidate['to']);

        foreach ($exceptions as $exception) {
            $start = Carbon::parse($exception['from']);
            $end = Carbon::parse($exception['to']);

            if ($candidateStart->lt($end) && $candidateEnd->gt($start)) {
                return true;
            }
        }

        return false;
    }
}

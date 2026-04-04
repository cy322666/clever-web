<?php

namespace App\Services\Distribution;

use Carbon\Carbon;
use DateTimeZone;

class ScheduleEvaluator
{
    public static function isWorkingNow(?string $settingsJson, Carbon $now, string $timezone): bool
    {
        $settings = json_decode($settingsJson ?? '[]', true);
        if (!is_array($settings) || count($settings) === 0) {
            return false;
        }

        $effectiveTimezone = self::normalizeTimezone($settings['timezone'] ?? null, $timezone);
        $now = $now->copy()->timezone($effectiveTimezone);

        $exceptions = is_array($settings['exceptions'] ?? null) ? $settings['exceptions'] : [];
        $exceptionResult = self::evaluateExceptions($exceptions, $now, $effectiveTimezone);
        if ($exceptionResult !== null) {
            return $exceptionResult;
        }

        $mode = (string)($settings['mode'] ?? 'weekly');

        return match ($mode) {
            'always' => true,
            'weekly' => self::evaluateWeeklyRules($settings['weekly_rules'] ?? [], $now),
            'cycle' => self::evaluateCycle($settings['cycle'] ?? [], $now, $effectiveTimezone),
            default => false,
        };
    }

    private static function normalizeTimezone(mixed $candidate, string $fallback): string
    {
        static $knownTimezones = null;
        if (!is_array($knownTimezones)) {
            $knownTimezones = DateTimeZone::listIdentifiers();
        }

        if (is_string($candidate) && in_array($candidate, $knownTimezones, true)) {
            return $candidate;
        }

        if (in_array($fallback, $knownTimezones, true)) {
            return $fallback;
        }

        return 'Europe/Moscow';
    }

    private static function evaluateExceptions(array $exceptions, Carbon $now, string $timezone): ?bool
    {
        $hasWorkOverride = false;

        foreach ($exceptions as $exception) {
            if (!is_array($exception)) {
                continue;
            }

            $from = self::parseDateTime($exception['from'] ?? null, $timezone);
            $to = self::parseDateTime($exception['to'] ?? null, $timezone);
            $type = $exception['type'] ?? null;

            if (!$from || !$to || !in_array($type, ['work', 'free'], true)) {
                continue;
            }

            if (!$now->betweenIncluded($from, $to)) {
                continue;
            }

            if ($type === 'free') {
                return false;
            }

            $hasWorkOverride = true;
        }

        return $hasWorkOverride ? true : null;
    }

    private static function parseDateTime(mixed $value, string $timezone): ?Carbon
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value, $timezone)->timezone($timezone);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function evaluateWeeklyRules(array $rules, Carbon $now): bool
    {
        if (!is_array($rules) || count($rules) === 0) {
            return false;
        }

        $nowDay = (int)$now->dayOfWeekIso;
        $nowSeconds = self::timeToSeconds($now->format('H:i:s'));

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $ruleDay = isset($rule['day']) ? (int)$rule['day'] : 0;
            $from = $rule['from'] ?? null;
            $to = $rule['to'] ?? null;

            if ($ruleDay < 1 || $ruleDay > 7 || !$from || !$to) {
                continue;
            }

            $fromSeconds = self::timeToSeconds($from);
            $toSeconds = self::timeToSeconds($to);
            if ($fromSeconds === null || $toSeconds === null) {
                continue;
            }

            if ($fromSeconds <= $toSeconds) {
                if ($nowDay === $ruleDay && $nowSeconds >= $fromSeconds && $nowSeconds <= $toSeconds) {
                    return true;
                }

                continue;
            }

            $nextDay = $ruleDay === 7 ? 1 : $ruleDay + 1;

            if ($nowDay === $ruleDay && $nowSeconds >= $fromSeconds) {
                return true;
            }

            if ($nowDay === $nextDay && $nowSeconds <= $toSeconds) {
                return true;
            }
        }

        return false;
    }

    private static function timeToSeconds(string $time): ?int
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

    private static function evaluateCycle(array $cycle, Carbon $now, string $timezone): bool
    {
        if (!is_array($cycle)) {
            return false;
        }

        $workDays = max(1, (int)($cycle['work_days'] ?? 0));
        $restDays = max(1, (int)($cycle['rest_days'] ?? 0));
        $totalDays = $workDays + $restDays;

        $anchorDate = self::parseDate($cycle['anchor_date'] ?? null, $timezone);
        if (!$anchorDate) {
            return false;
        }

        $delta = $anchorDate->diffInDays($now->copy()->startOfDay(), false);
        $position = (($delta % $totalDays) + $totalDays) % $totalDays;
        $isWorkDay = $position < $workDays;

        if (!$isWorkDay) {
            return false;
        }

        $from = $cycle['from'] ?? null;
        $to = $cycle['to'] ?? null;
        if (!$from || !$to) {
            return true;
        }

        $fromSeconds = self::timeToSeconds($from);
        $toSeconds = self::timeToSeconds($to);
        $nowSeconds = self::timeToSeconds($now->format('H:i:s'));

        if ($fromSeconds === null || $toSeconds === null || $nowSeconds === null) {
            return false;
        }

        if ($fromSeconds <= $toSeconds) {
            return $nowSeconds >= $fromSeconds && $nowSeconds <= $toSeconds;
        }

        return $nowSeconds >= $fromSeconds || $nowSeconds <= $toSeconds;
    }

    private static function parseDate(mixed $value, string $timezone): ?Carbon
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value, $timezone)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}

<?php

namespace App\Services\Finder;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class WorkingTime
{
    public function deadline(CarbonImmutable $from, int $seconds, array $options): CarbonImmutable
    {
        if (empty($options['working_time'])) {
            return $from->addSeconds($seconds);
        }

        $cursor = $from->setTimezone($options['timezone']);
        // Include yesterday for shifts crossing midnight. Merge overlapping shifts
        // so the same working minute is never counted twice.
        $day = $cursor->startOfDay()->subDay();
        $intervals = [];
        for ($i = 0; $i < 370; $i++, $day = $day->addDay()) {
            foreach ($options['schedule'] as $row) {
                if (! in_array($day->dayOfWeekIso, array_map('intval', $row['days']), true)) {
                    continue;
                }
                $start = $day->setTimeFromTimeString($row['from']);
                $end = $day->setTimeFromTimeString($row['to']);
                if ($end->lte($start)) {
                    $end = $end->addDay();
                }
                $intervals[] = [$start, $end];
            }
        }
        usort($intervals, fn ($a, $b) => $a[0]->getTimestamp() <=> $b[0]->getTimestamp());
        foreach ($intervals as [$start, $end]) {
            if ($end->lte($cursor)) {
                continue;
            }
            $cursor = $cursor->max($start);
            $available = $end->getTimestamp() - $cursor->getTimestamp();
            if ($seconds <= $available) {
                return $cursor->addSeconds($seconds)->setTimezone($from->getTimezone());
            }
            $seconds -= $available;
            $cursor = $end;
        }

        throw new InvalidArgumentException('В расписании недостаточно рабочего времени для заданного интервала.');
    }
}

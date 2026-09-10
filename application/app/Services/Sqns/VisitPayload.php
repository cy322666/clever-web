<?php

namespace App\Services\Sqns;

class VisitPayload
{
    public static function extract(array $payload): ?array
    {
        foreach (self::candidates($payload) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            if (self::id($candidate) !== null && self::looksLikeVisit($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public static function id(array $payload): ?int
    {
        $value = null;

        foreach ([
            'visit.id',
            'data.visit.id',
            'payload.visit.id',
            'body.visit.id',
            'body.data.visit.id',
            'body.payload.visit.id',
            'visitId',
            'visit_id',
            'data.visitId',
            'data.visit_id',
            'payload.visitId',
            'payload.visit_id',
            'body.visitId',
            'body.visit_id',
        ] as $path) {
            $candidate = data_get($payload, $path);

            if ($candidate !== null) {
                $value = $candidate;
                break;
            }
        }

        if ($value === null && self::looksLikeVisit($payload)) {
            $value = data_get($payload, 'id');
        }

        if ($value === null && self::isVisitEvent($payload)) {
            foreach ([
                data_get($payload, 'data.id'),
                data_get($payload, 'payload.id'),
                data_get($payload, 'body.id'),
                data_get($payload, 'body.data.id'),
                data_get($payload, 'body.payload.id'),
            ] as $candidate) {
                if ($candidate !== null) {
                    $value = $candidate;
                    break;
                }
            }
        }

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    public static function needsHydration(?array $visit): bool
    {
        if (! $visit) {
            return true;
        }

        return ! array_key_exists('datetime', $visit)
            || (! array_key_exists('clientData', $visit) && ! array_key_exists('clientId', $visit));
    }

    public static function partial(array $payload): ?array
    {
        $visitId = self::id($payload);

        if (! $visitId) {
            return null;
        }

        $visit = collect(self::candidates($payload))
            ->first(fn (mixed $candidate): bool => is_array($candidate) && array_key_exists('id', $candidate));
        $visit = is_array($visit) ? $visit : [];
        $visit['id'] = $visitId;

        $event = collect([
            data_get($payload, 'event'),
            data_get($payload, 'type'),
            data_get($payload, 'action'),
            data_get($payload, 'status'),
            data_get($payload, 'body.event'),
            data_get($payload, 'body.type'),
            data_get($payload, 'body.action'),
            data_get($payload, 'body.status'),
        ])->filter(fn (mixed $value): bool => is_scalar($value))
            ->map(fn (mixed $value): string => mb_strtolower((string) $value))
            ->implode(' ');

        if ($event !== '') {
            if (str_contains($event, 'delet') || str_contains($event, 'remov') || str_contains($event, 'destroy')) {
                $visit['deleted'] = true;
            } elseif (str_contains($event, 'cancel')) {
                $visit['attendance'] = -1;
            } elseif (str_contains($event, 'confirm')) {
                $visit['attendance'] = 2;
            } elseif (str_contains($event, 'show') || str_contains($event, 'came')) {
                $visit['attendance'] = 1;
            } elseif (str_contains($event, 'new') || str_contains($event, 'creat')) {
                $visit['attendance'] = 0;
            }
        }

        return $visit;
    }

    public static function merge(array $remote, ?array $webhook): array
    {
        if (! $webhook || self::isOlder($webhook, $remote)) {
            return $remote;
        }

        foreach ($webhook as $key => $value) {
            $remoteValue = $remote[$key] ?? null;

            if (
                is_array($value)
                && is_array($remoteValue)
                && ! array_is_list($value)
                && ! array_is_list($remoteValue)
            ) {
                $remote[$key] = self::merge($remoteValue, $value);

                continue;
            }

            $remote[$key] = $value;
        }

        return $remote;
    }

    public static function isOlder(array $candidate, array $current): bool
    {
        $candidateTimestamp = self::updateTimestamp($candidate);
        $currentTimestamp = self::updateTimestamp($current);

        return $candidateTimestamp !== null
            && $currentTimestamp !== null
            && $candidateTimestamp < $currentTimestamp;
    }

    private static function updateTimestamp(array $payload): ?int
    {
        $value = data_get($payload, 'update_date')
            ?? data_get($payload, 'updateAt')
            ?? data_get($payload, 'updated_at');

        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? null : $timestamp;
    }

    private static function candidates(array $payload): array
    {
        return [
            data_get($payload, 'visit'),
            data_get($payload, 'data.visit'),
            data_get($payload, 'payload.visit'),
            data_get($payload, 'body.visit'),
            data_get($payload, 'body.data.visit'),
            data_get($payload, 'body.payload.visit'),
            data_get($payload, 'data'),
            data_get($payload, 'payload'),
            data_get($payload, 'body.data'),
            data_get($payload, 'body.payload'),
            data_get($payload, 'body'),
            $payload,
        ];
    }

    private static function isVisitEvent(array $payload): bool
    {
        $event = collect([
            data_get($payload, 'event'),
            data_get($payload, 'type'),
            data_get($payload, 'action'),
            data_get($payload, 'body.event'),
            data_get($payload, 'body.type'),
            data_get($payload, 'body.action'),
        ])->filter(fn (mixed $value): bool => is_scalar($value))
            ->map(fn (mixed $value): string => mb_strtolower((string) $value))
            ->implode(' ');

        return str_contains($event, 'visit') || str_contains($event, 'appointment');
    }

    private static function looksLikeVisit(array $payload): bool
    {
        return array_key_exists('id', $payload)
            && collect([
                'datetime',
                'clientData',
                'clientId',
                'services',
                'attendance',
                'resourceId',
                'deleted',
            ])->contains(fn (string $key): bool => array_key_exists($key, $payload));
    }
}

<?php

namespace App\Services\Sqns;

class VisitPayload
{
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

        if ($value === null && self::hasVisitFields($payload)) {
            $value = data_get($payload, 'id');
        }

        if ($value === null && self::isVisitEvent($payload)) {
            foreach ([
                data_get($payload, 'data.id'),
                data_get($payload, 'payload.id'),
                data_get($payload, 'body.id'),
                data_get($payload, 'body.data.id'),
                data_get($payload, 'body.payload.id'),
                data_get($payload, 'id'),
            ] as $candidate) {
                if ($candidate !== null) {
                    $value = $candidate;
                    break;
                }
            }
        }

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    public static function extract(array $payload): ?array
    {
        $visitId = self::id($payload);

        if ($visitId === null) {
            return null;
        }

        $visit = [];

        foreach (self::candidates($payload) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $candidateId = $candidate['id'] ?? $candidate['visitId'] ?? $candidate['visit_id'] ?? null;
            if ($candidateId !== null && (! is_numeric($candidateId) || (int) $candidateId !== $visitId)) {
                continue;
            }

            if (self::hasVisitFields($candidate)) {
                $visit = $candidate;
                break;
            }

            if ($visit === [] && $candidateId !== null) {
                $visit = $candidate;
            }
        }

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

        if (str_contains($event, 'delet') || str_contains($event, 'remov') || str_contains($event, 'destroy')) {
            $visit['deleted'] ??= true;
        } elseif (! isset($visit['attendance'])) {
            $attendance = match (true) {
                str_contains($event, 'cancel') => -1,
                str_contains($event, 'confirm') => 2,
                str_contains($event, 'show') || str_contains($event, 'came') => 1,
                str_contains($event, 'new') || str_contains($event, 'creat') => 0,
                default => null,
            };

            if ($attendance !== null) {
                $visit['attendance'] = $attendance;
            }
        }

        if (! self::hasVisitFields($visit)) {
            return null;
        }

        $visit['id'] = $visitId;

        return $visit;
    }

    public static function merge(array $stored, array $incoming): array
    {
        if ($incoming === [] || self::isOlder($incoming, $stored)) {
            return $stored;
        }

        foreach ($incoming as $key => $value) {
            $storedValue = $stored[$key] ?? null;

            if (
                is_array($value)
                && is_array($storedValue)
                && ! array_is_list($value)
                && ! array_is_list($storedValue)
            ) {
                $stored[$key] = self::merge($storedValue, $value);

                continue;
            }

            $stored[$key] = $value;
        }

        return $stored;
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

    private static function hasVisitFields(array $payload): bool
    {
        return array_intersect(array_keys($payload), [
            'datetime',
            'clientData',
            'clientId',
            'services',
            'attendance',
            'resourceId',
            'deleted',
            'totalCost',
            'totalPrice',
            'organization',
            'online',
            'isPaid',
            'author',
            'comment',
            'commodities',
            'subscriptions',
            'certificates',
            'master_requested',
        ]) !== [];
    }
}

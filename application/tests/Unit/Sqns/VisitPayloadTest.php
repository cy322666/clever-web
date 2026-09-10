<?php

namespace Tests\Unit\Sqns;

use App\Services\Sqns\VisitPayload;
use PHPUnit\Framework\TestCase;

class VisitPayloadTest extends TestCase
{
    public function test_extracts_full_visit_from_webhook_wrapper(): void
    {
        $visit = VisitPayload::extract([
            'event' => 'visit.updated',
            'visit' => [
                'id' => 123,
                'datetime' => '2026-09-07 14:30:00',
                'clientId' => 55,
                'attendance' => 2,
            ],
        ]);

        $this->assertSame(123, VisitPayload::id($visit));
        $this->assertSame(2, $visit['attendance']);
        $this->assertFalse(VisitPayload::needsHydration($visit));
    }

    public function test_accepts_id_only_webhook_for_api_hydration(): void
    {
        $payload = ['visit' => ['id' => 987]];

        $this->assertSame(987, VisitPayload::id($payload));
        $this->assertNull(VisitPayload::extract($payload));
        $this->assertTrue(VisitPayload::needsHydration(null));
    }

    public function test_accepts_nested_body_and_id_only_data_wrappers(): void
    {
        $nested = [
            'body' => [
                'visit' => [
                    'id' => 654,
                    'datetime' => '2026-09-10 12:00:00',
                    'clientId' => 8,
                ],
            ],
        ];

        $this->assertSame(654, VisitPayload::id($nested));
        $this->assertSame(654, VisitPayload::extract($nested)['id']);

        $idOnly = ['event' => 'visit.updated', 'data' => ['id' => 655]];

        $this->assertSame(655, VisitPayload::id($idOnly));
        $this->assertSame(655, VisitPayload::partial($idOnly)['id']);
    }

    public function test_infers_deleted_state_from_id_only_event(): void
    {
        $visit = VisitPayload::partial([
            'event' => 'visit.deleted',
            'visitId' => 987,
        ]);

        $this->assertSame(987, $visit['id']);
        $this->assertTrue($visit['deleted']);
    }

    public function test_merges_remote_visit_with_newer_webhook_values(): void
    {
        $merged = VisitPayload::merge([
            'id' => 123,
            'attendance' => 0,
            'clientData' => ['id' => 5, 'name' => 'Иван'],
        ], [
            'id' => 123,
            'attendance' => -1,
        ]);

        $this->assertSame(-1, $merged['attendance']);
        $this->assertSame('Иван', $merged['clientData']['name']);
    }

    public function test_merge_replaces_lists_but_preserves_unmodified_object_fields(): void
    {
        $merged = VisitPayload::merge([
            'services' => [
                ['id' => 1, 'name' => 'Первая'],
                ['id' => 2, 'name' => 'Вторая'],
            ],
            'clientData' => [
                'id' => 5,
                'name' => 'Иван',
                'phone' => '+79000000000',
            ],
        ], [
            'services' => [
                ['id' => 3, 'name' => 'Новая'],
            ],
            'clientData' => [
                'name' => 'Иван Петров',
            ],
        ]);

        $this->assertSame([['id' => 3, 'name' => 'Новая']], $merged['services']);
        $this->assertSame('Иван Петров', $merged['clientData']['name']);
        $this->assertSame('+79000000000', $merged['clientData']['phone']);
    }

    public function test_merge_does_not_apply_an_older_visit_snapshot(): void
    {
        $merged = VisitPayload::merge([
            'id' => 123,
            'attendance' => 2,
            'update_date' => '2026-09-10 12:00:00',
        ], [
            'id' => 123,
            'attendance' => 0,
            'update_date' => '2026-09-10 11:00:00',
        ]);

        $this->assertSame(2, $merged['attendance']);
        $this->assertSame('2026-09-10 12:00:00', $merged['update_date']);
    }
}

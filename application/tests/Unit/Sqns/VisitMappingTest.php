<?php

namespace Tests\Unit\Sqns;

use App\Jobs\Sqns\ProcessWebhook;
use App\Jobs\Sqns\SyncVisits;
use App\Jobs\Sqns\SyncVisitToAmo;
use App\Models\Integrations\Sqns\Client;
use App\Models\Integrations\Sqns\Setting;
use App\Models\Integrations\Sqns\Visit;
use App\Services\Sqns\AmoFieldMapper;
use Tests\TestCase;

class VisitMappingTest extends TestCase
{
    public function test_maps_sqns_attendance_to_configured_amo_status(): void
    {
        $setting = new Setting([
            'status_id_wait' => '100.200',
            'status_id_confirm' => '100.201',
            'status_id_came' => '100.202',
            'status_id_cancel' => '100.203',
            'status_id_delete' => '100.204',
        ]);
        $visit = new Visit(['attendance' => 2, 'deleted' => false]);

        $status = $visit->amoStatus($setting);

        $this->assertSame('100', $status->pipeline_id);
        $this->assertSame('201', $status->status_id);
        $this->assertSame('Клиент подтвердил', $visit->eventLabel());

        $visit->deleted = true;
        $this->assertSame('204', $visit->amoStatus($setting)->status_id);
    }

    public function test_builds_field_values_from_visit_and_client(): void
    {
        $visit = new Visit([
            'visit_id' => 123,
            'datetime' => '2026-09-07 14:30:00',
            'attendance' => 1,
            'services' => 'Консультация',
            'cost' => 3500,
            'online' => true,
            'body' => [
                'totalPrice' => '4000',
                'totalCost' => '3500',
                'commodities' => [['name' => 'Щётка']],
                'subscriptions' => [['name' => 'Годовой']],
                'certificates' => [['name' => 'Подарочный']],
                'master_requested' => true,
            ],
        ]);
        $client = new Client([
            'client_id' => 55,
            'name' => 'Иван Иванов',
            'phone' => '+7 900 000-00-00',
            'sex' => 1,
            'tags' => ['Повторный'],
            'additional_phone' => '+7 911 000-00-00',
            'body' => [
                'comment' => 'Предпочитает утро',
                'type' => 'Постоянный',
                'address' => 'Калининград',
            ],
        ]);

        $values = AmoFieldMapper::values($visit, $client);

        $this->assertSame(123, $values['visit_id']);
        $this->assertSame('07.09.2026', $values['visit_date']);
        $this->assertSame('Консультация', $values['services']);
        $this->assertSame('Щётка', $values['commodities']);
        $this->assertSame('Годовой', $values['subscriptions']);
        $this->assertSame('Подарочный', $values['certificates']);
        $this->assertSame('+7 911 000-00-00', $values['client_additional_phone']);
        $this->assertSame('Предпочитает утро', $values['client_comment']);
        $this->assertSame('М', $values['client_sex']);
        $this->assertSame(['Повторный'], $values['client_tags']);
    }

    public function test_setting_is_ready_only_with_token_and_all_status_mappings(): void
    {
        $setting = new Setting([
            'token' => 'token',
            'status_id_wait' => '100.200',
            'status_id_confirm' => '100.201',
            'status_id_came' => '100.202',
            'status_id_cancel' => '100.203',
            'status_id_delete' => '100.204',
        ]);

        $this->assertTrue($setting->isReadyToSync());

        $setting->status_id_delete = null;
        $this->assertFalse($setting->isReadyToSync());

        $setting->status_id_delete = 'invalid';
        $this->assertFalse($setting->isReadyToSync());
    }

    public function test_jobs_use_a_dedicated_queue_with_a_safe_retry_window(): void
    {
        $jobs = [
            new ProcessWebhook(1, 2, ['visitId' => 3]),
            new SyncVisitToAmo(1),
            new SyncVisits(1, '2026-09-01', '2026-09-30'),
        ];

        foreach ($jobs as $job) {
            $this->assertSame('redis-sqns', $job->connection);
            $this->assertSame('sqns_visit', $job->queue);
        }

        $this->assertGreaterThan(
            max(array_map(fn ($job): int => $job->timeout, $jobs)),
            config('queue.connections.redis-sqns.retry_after'),
        );
        $this->assertSame('redis-sqns', config('horizon.defaults.supervisor-sqns.connection'));
    }
}

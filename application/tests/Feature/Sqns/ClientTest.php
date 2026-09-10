<?php

namespace Tests\Feature\Sqns;

use App\Models\Integrations\Sqns\Setting;
use App\Services\Sqns\Client;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ClientTest extends TestCase
{
    public function test_authenticates_and_registers_webhook(): void
    {
        Http::fake([
            'https://crm3.sqns.ru/api/v2/auth' => Http::response([
                'token' => 'new-token',
                'webhookSecret' => 'hook-secret',
                'user' => ['orgId' => 77],
            ]),
            'https://crm3.sqns.ru/api/v2/hook_settings' => Http::response([], 200),
        ]);

        $setting = Mockery::mock(Setting::class)->makePartial();
        $setting->fill([
            'api_base_url' => 'https://crm3.sqns.ru',
            'email' => 'owner@example.test',
            'password' => 'secret',
        ]);
        $setting->shouldReceive('save')->once()->andReturnTrue();

        (new Client($setting))->connect('https://clever.example/api/sqns/hook/user/key');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://crm3.sqns.ru/api/v2/auth'
            && $request['email'] === 'owner@example.test'
            && ! $request->hasHeader('authorization')
        );
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://crm3.sqns.ru/api/v2/hook_settings'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer new-token')
            && $request['urls'] === ['https://clever.example/api/sqns/hook/user/key']
        );
        $this->assertSame(77, $setting->organization_id);
        $this->assertSame('new-token', $setting->token);
    }

    public function test_reads_visit_with_sqns_authorization_header(): void
    {
        Http::fake([
            'https://crm3.sqns.ru/api/v2/visit/123' => Http::response([
                'id' => 123,
                'datetime' => '2026-09-07 14:30:00',
                'clientId' => 55,
            ]),
        ]);

        $client = new Client($this->setting());
        $visit = $client->getVisit(123);

        $this->assertSame(123, $visit['id']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://crm3.sqns.ru/api/v2/visit/123'
            && $request->hasHeader('Authorization', 'Bearer test-token')
        );
    }

    public function test_reads_visit_from_nested_api_response(): void
    {
        Http::fake([
            'https://crm3.sqns.ru/api/v2/visit/123' => Http::response([
                'data' => [
                    'visit' => [
                        'id' => 123,
                        'datetime' => '2026-09-07 14:30:00',
                        'clientId' => 55,
                    ],
                ],
            ]),
        ]);

        $visit = (new Client($this->setting()))->getVisit(123);

        $this->assertSame(123, $visit['id']);
        $this->assertSame(55, $visit['clientId']);
    }

    public function test_rejects_a_visit_response_with_another_id(): void
    {
        Http::fake([
            'https://crm3.sqns.ru/api/v2/visit/123' => Http::response([
                'id' => 456,
                'datetime' => '2026-09-07 14:30:00',
                'clientId' => 55,
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SQNS вернул некорректные данные визита #123.');

        (new Client($this->setting()))->getVisit(123);
    }

    public function test_updates_webhook_when_reconnecting_existing_setting(): void
    {
        Http::fake([
            'https://crm3.sqns.ru/api/v2/auth' => Http::response([
                'token' => 'new-token',
            ]),
            'https://crm3.sqns.ru/api/v2/hook_settings' => Http::response(['status' => 'success']),
        ]);

        $setting = Mockery::mock(Setting::class)->makePartial();
        $setting->fill([
            'api_base_url' => 'https://crm3.sqns.ru',
            'email' => 'owner@example.test',
            'password' => 'secret',
            'connected_at' => now(),
        ]);
        $setting->shouldReceive('save')->once()->andReturnTrue();

        (new Client($setting))->connect('https://clever.example/api/sqns/hook/user/key');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://crm3.sqns.ru/api/v2/hook_settings'
            && $request->method() === 'PATCH'
            && $request->hasHeader('Authorization', 'Bearer new-token')
        );
    }

    public function test_requests_paginated_visits_with_documented_filters(): void
    {
        Http::fake([
            'https://crm3.sqns.ru/api/v2/visit*' => Http::response([
                'data' => [],
                'meta' => ['page' => 2, 'lastPage' => 2],
            ]),
        ]);

        $response = (new Client($this->setting()))
            ->listVisits(2, '2026-09-01', '2026-09-30');

        $this->assertSame(2, data_get($response, 'meta.page'));
        Http::assertSent(fn (Request $request): bool => $request['page'] === 2
            && $request['perPage'] === 100
            && $request['dateFrom'] === '2026-09-01'
            && $request['dateTill'] === '2026-09-30'
        );
    }

    public function test_rejects_untrusted_api_host(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Недопустимый адрес API SQNS.');

        $setting = $this->setting();
        $setting->api_base_url = 'https://example.test';

        (new Client($setting))->getVisit(123);
    }

    public function test_retries_transient_sqns_server_errors(): void
    {
        Http::fakeSequence('https://crm3.sqns.ru/api/v2/visit/123')
            ->push(['message' => 'temporary'], 500)
            ->push([
                'id' => 123,
                'datetime' => '2026-09-10 12:00:00',
                'clientId' => 55,
            ], 200);

        $visit = (new Client($this->setting()))->getVisit(123);

        $this->assertSame(123, $visit['id']);
        Http::assertSentCount(2);
    }

    private function setting(): Setting
    {
        return new Setting([
            'api_base_url' => 'https://crm3.sqns.ru',
            'token' => 'test-token',
        ]);
    }
}

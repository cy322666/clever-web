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
        $this->assertSame('https://clever.example/api/sqns/hook/user/key', $setting->registered_webhook_url);
    }

    public function test_detects_the_sqns_server_during_authentication(): void
    {
        Http::fake([
            'https://crm3.sqns.ru/api/v2/auth' => Http::response([
                'message' => 'user not found',
            ], 422),
            'https://crm2.sqns.ru/api/v2/auth' => Http::response([
                'token' => 'crm2-token',
                'user' => ['orgId' => 88],
            ]),
            'https://crm2.sqns.ru/api/v2/hook_settings' => Http::response([], 200),
        ]);

        $setting = Mockery::mock(Setting::class)->makePartial();
        $setting->fill([
            'api_base_url' => 'https://crm3.sqns.ru',
            'email' => 'owner@example.test',
            'password' => 'secret',
        ]);
        $setting->shouldReceive('save')->once()->andReturnTrue();

        (new Client($setting))->connect('https://clever.example/api/sqns/hook/user/key');

        $requests = collect(Http::recorded())->map(fn (array $entry) => $entry[0])->values();

        $this->assertSame('https://crm3.sqns.ru/api/v2/auth', $requests[0]->url());
        $this->assertSame('https://crm2.sqns.ru/api/v2/auth', $requests[1]->url());
        $this->assertSame('https://crm2.sqns.ru/api/v2/hook_settings', $requests[2]->url());
        $this->assertSame('https://crm2.sqns.ru', $setting->api_base_url);
        $this->assertSame('crm2-token', $setting->token);
        $this->assertSame(88, $setting->organization_id);
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

    public function test_disconnect_removes_webhook_and_clears_connection_state(): void
    {
        Http::fake([
            'https://crm3.sqns.ru/api/v2/hook_settings' => Http::response([], 200),
        ]);

        $setting = Mockery::mock(Setting::class)->makePartial();
        $setting->fill([
            'api_base_url' => 'https://crm3.sqns.ru',
            'token' => 'test-token',
            'webhook_secret' => 'hook-secret',
            'webhook_key' => 'old-webhook-key',
            'registered_webhook_url' => 'https://old-clever.example/api/sqns/hook/user/key',
            'organization_id' => 77,
            'organization_name' => 'Clinic',
            'connected_at' => now(),
        ]);
        $setting->shouldReceive('save')->once()->andReturnTrue();

        (new Client($setting))->disconnect('https://new-clever.example/api/sqns/hook/user/key');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://crm3.sqns.ru/api/v2/hook_settings'
            && $request->method() === 'DELETE'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['urls'] === ['https://old-clever.example/api/sqns/hook/user/key']
        );
        $this->assertNull($setting->token);
        $this->assertNull($setting->webhook_secret);
        $this->assertNotSame('old-webhook-key', $setting->webhook_key);
        $this->assertSame(48, strlen((string) $setting->webhook_key));
        $this->assertNull($setting->registered_webhook_url);
        $this->assertNull($setting->organization_id);
        $this->assertNull($setting->organization_name);
        $this->assertNull($setting->connected_at);
    }

    public function test_disconnect_accepts_an_already_missing_webhook(): void
    {
        Http::fake([
            'https://crm3.sqns.ru/api/v2/hook_settings' => Http::response(['message' => 'not found'], 404),
        ]);

        $setting = Mockery::mock(Setting::class)->makePartial();
        $setting->fill([
            'api_base_url' => 'https://crm3.sqns.ru',
            'token' => 'test-token',
            'connected_at' => now(),
        ]);
        $setting->shouldReceive('save')->once()->andReturnTrue();

        (new Client($setting))->disconnect('https://clever.example/api/sqns/hook/user/key');

        $this->assertNull($setting->token);
        Http::assertSentCount(1);
    }

    public function test_disconnect_reauthenticates_when_the_stored_token_is_rejected(): void
    {
        Http::fake([
            'https://crm3.sqns.ru/api/v2/auth' => Http::response(['token' => 'renewed-token']),
            'https://crm3.sqns.ru/api/v2/hook_settings' => Http::sequence()
                ->push(['message' => 'unauthorized'], 401)
                ->push([], 200),
        ]);

        $setting = Mockery::mock(Setting::class)->makePartial();
        $setting->fill([
            'api_base_url' => 'https://crm3.sqns.ru',
            'email' => 'owner@example.test',
            'password' => 'secret',
            'token' => 'expired-token',
            'connected_at' => now(),
        ]);
        $setting->shouldReceive('save')->once()->andReturnTrue();

        (new Client($setting))->disconnect('https://clever.example/api/sqns/hook/user/key');

        $deleteRequests = collect(Http::recorded())
            ->map(fn (array $entry) => $entry[0])
            ->filter(fn (Request $request): bool => $request->method() === 'DELETE')
            ->values();

        $this->assertCount(2, $deleteRequests);
        $this->assertTrue($deleteRequests[0]->hasHeader('Authorization', 'Bearer expired-token'));
        $this->assertTrue($deleteRequests[1]->hasHeader('Authorization', 'Bearer renewed-token'));
        $this->assertNull($setting->token);
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
            && $request->hasHeader('Authorization', 'Bearer test-token')
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

        (new Client($setting))->listVisits(1, '2026-09-01', '2026-09-30');
    }

    public function test_retries_transient_sqns_server_errors(): void
    {
        Http::fakeSequence('https://crm3.sqns.ru/api/v2/visit*')
            ->push(['message' => 'temporary'], 500)
            ->push(['data' => [['id' => 123]]], 200);

        $response = (new Client($this->setting()))->listVisits(1, '2026-09-01', '2026-09-30');

        $this->assertSame(123, data_get($response, 'data.0.id'));
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

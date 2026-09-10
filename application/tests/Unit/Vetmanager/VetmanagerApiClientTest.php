<?php

namespace Tests\Unit\Vetmanager;

use App\Models\Integrations\Vetmanager\Setting;
use App\Services\Vetmanager\VetmanagerApiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class VetmanagerApiClientTest extends TestCase
{
    public function test_reads_admission_with_api_key_and_timezone_headers(): void
    {
        Http::fake([
            'https://clinic.vetmanager.ru/rest/api/Admission/123' => Http::response([
                'success' => true,
                'data' => [
                    'admission' => ['id' => '123', 'client_id' => '45'],
                ],
            ]),
        ]);

        $setting = new Setting([
            'base_url' => 'clinic.vetmanager.ru',
            'api_key' => 'test-key',
            'timezone' => 'Europe/Moscow',
        ]);

        $admission = (new VetmanagerApiClient($setting))->admission('123');

        $this->assertSame('45', $admission['client_id']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://clinic.vetmanager.ru/rest/api/Admission/123'
            && $request->hasHeader('X-REST-API-KEY', 'test-key')
            && $request->hasHeader('X-REST-TIME-ZONE', 'Europe/Moscow'));
    }

    public function test_rejects_non_vetmanager_host(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Адрес должен принадлежать домену Vetmanager.');

        VetmanagerApiClient::normalizeBaseUrl('https://example.test');
    }

    public function test_uses_legacy_webhook_catalog_when_modern_endpoint_is_unavailable(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/rest/api/webHook')) {
                return Http::response([], 404);
            }

            if (str_contains($request->url(), '/rest/api/ComboManualName')) {
                return Http::response([
                    'success' => true,
                    'data' => [
                        'comboManualName' => [[
                            'id' => 11,
                            'name' => 'services_for_hooks',
                        ]],
                    ],
                ]);
            }

            if ($request->method() === 'GET' && str_contains($request->url(), '/rest/api/ComboManualItem')) {
                return Http::response([
                    'success' => true,
                    'data' => ['comboManualItem' => []],
                ]);
            }

            if ($request->method() === 'POST' && str_contains($request->url(), '/rest/api/ComboManualItem')) {
                return Http::response([
                    'success' => true,
                    'data' => ['comboManualItem' => [['id' => 230]]],
                ]);
            }

            return Http::response([], 500);
        });

        $client = new VetmanagerApiClient(new Setting([
            'base_url' => 'teovet.vetmanager2.ru',
            'api_key' => 'test-key',
        ]));

        $this->assertSame([], $client->webhooks());
        $webhook = $client->createWebhook([
            'title' => 'CleverCRM',
            'value' => 'https://example.test/hook',
            'dop_param3' => 'admissionAccepted',
            'is_active' => 1,
        ]);

        $this->assertSame(230, $webhook['id']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/rest/api/ComboManualItem')
            && $request['combo_manual_id'] === 11);
    }

    public function test_accepts_empty_success_response_when_updating_webhook(): void
    {
        Http::fake([
            'https://clinic.vetmanager.ru/rest/api/webHook*' => Http::sequence()
                ->push(['success' => true, 'data' => ['webHook' => []]])
                ->push('', 204),
        ]);

        $client = new VetmanagerApiClient(new Setting([
            'base_url' => 'clinic.vetmanager.ru',
            'api_key' => 'test-key',
        ]));

        $client->webhooks();
        $webhook = $client->updateWebhook('230', [
            'title' => 'CleverCRM',
            'value' => 'https://example.test/hook',
        ]);

        $this->assertSame('230', $webhook['id']);
        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PUT') {
                return false;
            }

            return $request->hasHeader('Content-Type', 'text/plain')
                && json_decode($request->body(), true) === [
                    'title' => 'CleverCRM',
                    'value' => 'https://example.test/hook',
                ];
        });
    }

    public function test_rejects_http_and_credentials_in_url(): void
    {
        foreach (['http://clinic.vetmanager.ru', 'https://user@clinic.vetmanager.ru'] as $url) {
            try {
                VetmanagerApiClient::normalizeBaseUrl($url);
                $this->fail('Unsafe URL was accepted: '.$url);
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('HTTPS-адрес', $exception->getMessage());
            }
        }
    }
}

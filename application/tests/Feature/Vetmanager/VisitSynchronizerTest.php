<?php

namespace Tests\Feature\Vetmanager;

use App\Models\Core\Account;
use App\Models\Integrations\Vetmanager\Setting;
use App\Models\Integrations\Vetmanager\Visit;
use App\Services\Vetmanager\AmoCrmGateway;
use App\Services\Vetmanager\VisitSynchronizer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VisitSynchronizerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->boolean('active')->default(false);
            $table->string('subdomain')->nullable();
            $table->string('zone')->nullable();
        });

        $migration = require database_path('migrations/2026_09_07_120000_create_vetmanager_integration_tables.php');
        $migration->up();
    }

    public function test_repeated_sync_creates_only_one_contact_and_one_lead(): void
    {
        DB::table('users')->insert(['id' => 1]);
        $account = new Account;
        $account->forceFill([
            'id' => 10,
            'active' => true,
            'subdomain' => 'clinic',
            'zone' => 'ru',
        ])->save();
        $account = Account::query()->findOrFail(10);
        $setting = Setting::query()->create([
            'active' => true,
            'base_url' => 'clinic.vetmanager.ru',
            'api_key' => 'test-key',
            'timezone' => 'Europe/Moscow',
            'target_status' => '100.200',
            'sync_price' => true,
            'user_id' => 1,
            'account_id' => $account->id,
        ]);
        $visit = Visit::query()->create([
            'external_id' => '123',
            'event_name' => 'admissionAccepted',
            'event_payload' => [
                'data' => [
                    'id' => '123',
                    'invoices_sum' => '3500.00',
                ],
            ],
            'status' => Visit::STATUS_PENDING,
            'user_id' => 1,
            'account_id' => $account->id,
            'setting_id' => $setting->id,
        ]);

        Http::fake([
            'https://clinic.vetmanager.ru/rest/api/Admission/123' => Http::response([
                'success' => true,
                'data' => [
                    'admission' => [
                        'id' => '123',
                        'client_id' => '45',
                        'patient_id' => '67',
                        'admission_date' => '2026-09-07 14:30:00',
                        'client' => [
                            'last_name' => 'Иванов',
                            'first_name' => 'Иван',
                            'phone_prefix' => '7',
                            'cell_phone' => '8 900 123-45-67',
                            'email' => 'owner@example.test',
                        ],
                        'pet' => ['alias' => 'Барсик'],
                    ],
                ],
            ]),
        ]);

        $gateway = new class extends AmoCrmGateway
        {
            /** @var array<int, array{method: string, path: string, payload: array, query: array}> */
            public array $calls = [];

            public function request(
                Account $account,
                string $method,
                string $path,
                array $payload = [],
                array $query = [],
            ): array {
                $this->calls[] = compact('method', 'path', 'payload', 'query');

                if ($method === 'GET' && $path === '/api/v4/contacts/501') {
                    return [
                        'id' => 501,
                        'custom_fields_values' => [
                            ['field_code' => 'PHONE', 'values' => [['value' => '+79001234567']]],
                            ['field_code' => 'EMAIL', 'values' => [['value' => 'owner@example.test']]],
                        ],
                    ];
                }

                if ($method === 'GET' && $path === '/api/v4/leads/601') {
                    return [
                        'id' => 601,
                        'name' => '[VM#123] 07.09.2026 14:30 Барсик - Иванов Иван',
                        '_embedded' => ['contacts' => [['id' => 501]]],
                    ];
                }

                if ($method === 'GET' && $path === '/api/v4/contacts') {
                    return ['_embedded' => ['contacts' => []]];
                }

                if ($method === 'GET' && $path === '/api/v4/leads') {
                    return ['_embedded' => ['leads' => []]];
                }

                if ($method === 'POST' && $path === '/api/v4/contacts') {
                    return ['_embedded' => ['contacts' => [['id' => 501]]]];
                }

                if ($method === 'POST' && $path === '/api/v4/leads') {
                    return ['_embedded' => ['leads' => [['id' => 601]]]];
                }

                if ($method === 'PATCH' && $path === '/api/v4/leads/601') {
                    return [];
                }

                $this->failUnexpectedCall($method, $path);
            }

            private function failUnexpectedCall(string $method, string $path): never
            {
                throw new \RuntimeException('Unexpected amoCRM call: '.$method.' '.$path);
            }
        };

        $synchronizer = new VisitSynchronizer($gateway);
        $synchronizer->synchronize($visit);
        $synchronizer->synchronize($visit->refresh());

        $createContactCalls = collect($gateway->calls)
            ->where('method', 'POST')
            ->where('path', '/api/v4/contacts');
        $createLeadCalls = collect($gateway->calls)
            ->where('method', 'POST')
            ->where('path', '/api/v4/leads');

        $this->assertCount(1, $createContactCalls);
        $this->assertCount(1, $createLeadCalls);
        $this->assertSame('Иванов Иван', data_get($createContactCalls->first(), 'payload.0.name'));
        $this->assertSame(3500, data_get($createLeadCalls->first(), 'payload.0.price'));
        $this->assertSame(100, data_get($createLeadCalls->first(), 'payload.0.pipeline_id'));
        $this->assertSame(200, data_get($createLeadCalls->first(), 'payload.0.status_id'));
        $this->assertSame(501, data_get($createLeadCalls->first(), 'payload.0._embedded.contacts.0.id'));
        $this->assertSame(501, $visit->refresh()->contact_id);
        $this->assertSame(601, $visit->lead_id);

        $updateLeadCall = collect($gateway->calls)
            ->first(fn (array $call): bool => $call['method'] === 'PATCH' && $call['path'] === '/api/v4/leads/601');
        $this->assertIsArray($updateLeadCall);
        $this->assertArrayNotHasKey('pipeline_id', $updateLeadCall['payload']);
        $this->assertArrayNotHasKey('status_id', $updateLeadCall['payload']);

        foreach ($gateway->calls as $call) {
            $this->assertMatchesRegularExpression('#^/api/v4/(contacts|leads)(?:/|$)#', $call['path']);
            $this->assertStringNotContainsString('customers', $call['path']);
            $this->assertStringNotContainsString('purchases', $call['path']);
            $this->assertStringNotContainsString('transactions', $call['path']);
        }
    }
}

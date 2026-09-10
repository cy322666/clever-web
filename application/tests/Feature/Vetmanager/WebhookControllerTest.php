<?php

namespace Tests\Feature\Vetmanager;

use App\Http\Controllers\Api\VetmanagerController;
use App\Jobs\Vetmanager\SyncVisit;
use App\Models\Core\Account;
use App\Models\Integrations\Vetmanager\Setting;
use App\Models\Integrations\Vetmanager\Visit;
use App\Models\User;
use App\Services\Vetmanager\VetmanagerWebhookManager;
use App\Services\Vetmanager\WebhookPayloadNormalizer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class WebhookControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'queue.default' => 'sync',
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
        });
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->boolean('active')->default(false);
        });

        $migration = require database_path('migrations/2026_09_07_120000_create_vetmanager_integration_tables.php');
        $migration->up();
    }

    public function test_supported_events_are_idempotent_and_do_not_store_the_secret(): void
    {
        Bus::fake();
        $user = $this->user();
        $account = $this->account(active: true);
        $setting = $this->setting($user, $account);

        $first = $this->send($user, $setting, 'admissionAccepted', '1200.00');
        $second = $this->send($user, $setting, 'admissionInvoicesSumChanged', '1500.00');

        $this->assertSame(202, $first->getStatusCode());
        $this->assertSame(202, $second->getStatusCode());
        $this->assertSame(1, Visit::query()->count());

        $visit = Visit::query()->firstOrFail();
        $this->assertSame('admissionInvoicesSumChanged', $visit->event_name);
        $this->assertSame('1500.00', $visit->amount);
        $this->assertSame('***', data_get($visit->event_payload, 'params.dop_param1'));
        Bus::assertDispatchedTimes(SyncVisit::class, 2);
    }

    public function test_rejects_an_invalid_secret_without_persisting_or_dispatching(): void
    {
        Bus::fake();
        $user = $this->user();
        $setting = $this->setting($user, $this->account(active: true));
        $request = $this->request('admissionAccepted', 'invalid-secret', '1200.00');

        $response = app(VetmanagerController::class)->hook(
            $user,
            $request,
            app(WebhookPayloadNormalizer::class),
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, Visit::query()->count());
        Bus::assertNothingDispatched();
    }

    public function test_rejects_a_setting_bound_to_an_inactive_amo_account(): void
    {
        Bus::fake();
        $user = $this->user();
        $setting = $this->setting($user, $this->account(active: false));

        $response = app(VetmanagerController::class)->hook(
            $user,
            $this->request('admissionAccepted', (string) $setting->webhook_secret, '1200.00'),
            app(WebhookPayloadNormalizer::class),
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, Visit::query()->count());
        Bus::assertNothingDispatched();
    }

    public function test_installs_the_expected_vetmanager_webhook(): void
    {
        URL::forceRootUrl('https://app.clevercrm.pro');
        URL::forceScheme('https');
        $user = $this->user();
        $setting = $this->setting($user, $this->account(active: true));
        Http::fake(function (ClientRequest $request) {
            if ($request->method() === 'GET') {
                return Http::response(['success' => true, 'data' => ['webHook' => []]]);
            }

            return Http::response([
                'success' => true,
                'data' => ['webHook' => [['id' => '230']]],
            ]);
        });

        app(VetmanagerWebhookManager::class)->synchronize($setting);

        $this->assertSame('230', $setting->refresh()->webhook_id);
        Http::assertSent(function (ClientRequest $request) use ($setting): bool {
            if ($request->method() !== 'POST') {
                return false;
            }

            $payload = json_decode($request->body(), true);

            return $request->hasHeader('Content-Type', 'text/plain')
                && str_ends_with((string) ($payload['value'] ?? ''), '/api/vetmanager/hook/user-uuid')
                && ($payload['dop_param1'] ?? null) === $setting->webhook_secret
                && ($payload['dop_param3'] ?? null) === 'admissionAccepted,admissionInvoicesSumChanged'
                && ($payload['is_active'] ?? null) === 1;
        });
    }

    private function send(User $user, Setting $setting, string $event, string $amount): JsonResponse
    {
        return app(VetmanagerController::class)->hook(
            $user,
            $this->request($event, (string) $setting->webhook_secret, $amount),
            app(WebhookPayloadNormalizer::class),
        );
    }

    private function request(string $event, string $secret, string $amount): Request
    {
        return Request::create('/api/vetmanager/hook/user-uuid', 'POST', [
            'name' => $event,
            'data' => [
                'id' => '123',
                'client_id' => '45',
                'patient_id' => '67',
                'status' => 'accepted',
                'admission_date' => '2026-09-10 12:00:00',
                'invoices_sum' => $amount,
            ],
            'params' => ['dop_param1' => $secret],
        ]);
    }

    private function user(): User
    {
        DB::table('users')->insert(['id' => 1, 'uuid' => 'user-uuid']);

        return User::query()->findOrFail(1);
    }

    private function account(bool $active): Account
    {
        $account = new Account;
        $account->forceFill(['id' => 10, 'active' => $active])->save();

        return Account::query()->findOrFail(10);
    }

    private function setting(User $user, Account $account): Setting
    {
        return Setting::query()->create([
            'active' => true,
            'base_url' => 'clinic.vetmanager.ru',
            'api_key' => 'test-key',
            'timezone' => 'Europe/Moscow',
            'target_status' => '100.200',
            'user_id' => $user->id,
            'account_id' => $account->id,
        ]);
    }
}

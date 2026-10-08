<?php

namespace Tests\Feature\Integrations;

use App\Exceptions\AmoCrmOwnershipConflict;
use App\Jobs\Integrations\SendAmoCrmOwnershipAlert;
use App\Models\Core\Account;
use App\Models\Workflows\Workflow;
use App\Services\amoCRM\Client;
use App\Services\Billing\WidgetSubscriptionAccessService;
use App\Services\Integrations\AmoCrmWidgetLifecycleTelegramNotifier;
use App\Services\Workflows\WorkflowManualAmoCrmRunService;
use App\Services\Workflows\WorkflowRequestAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AmoCrmTenantIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'isolation_test',
            'database.connections.isolation_test' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'alerts.cache_store' => 'array', 'cache.default' => 'array',
            'services.amocrm.widgets.workflows.client_id' => 'workflow-client',
            'services.amocrm.widgets.workflows.client_secret' => 'local-workflow-secret',
            'services.amocrm.widgets.workflows.redirect_uri' => 'https://platform.example/callback',
            'services.amocrm.widgets.import-excel.client_id' => 'excel-client',
            'services.amocrm.widgets.import-excel.client_secret' => 'local-excel-secret',
            'widget_lifecycle.telegram.token' => 'test-bot', 'widget_lifecycle.telegram.chat_id' => 'test-chat']);
        DB::purge('isolation_test');
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email');
            $t->string('password');
            $t->uuid('uuid')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('widget');
            $t->unsignedBigInteger('amo_account_id')->nullable();
            $t->string('subdomain')->nullable();
            $t->string('zone')->nullable();
            $t->string('client_id')->nullable();
            $t->string('client_secret')->nullable();
            $t->string('redirect_uri')->nullable();
            $t->text('code')->nullable();
            $t->text('access_token')->nullable();
            $t->text('refresh_token')->nullable();
            $t->boolean('active')->default(false);
            $t->integer('expires_in')->nullable();
            $t->integer('created_at')->nullable();
            $t->unique(['user_id', 'widget']);
            $t->unique(['amo_account_id', 'widget']);
        });
        Schema::create('workflows', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('name');
            $t->boolean('is_active');
            $t->json('definition');
            $t->softDeletes();
        });
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $this->mock(AmoCrmWidgetLifecycleTelegramNotifier::class)->shouldReceive('notify')->andReturnNull();
        $this->mock(WidgetSubscriptionAccessService::class)->shouldReceive('canUse')->andReturnTrue();
    }

    private function connection(string $domain, int $crmId, string $widget = 'workflows', ?int $userId = null): Account
    {
        $userId ??= DB::table('users')->insertGetId(['name' => 'Audit user', 'email' => $domain.'@example.com', 'password' => 'local-only']);
        $a = new Account;
        $a->forceFill(['user_id' => $userId, 'widget' => $widget, 'amo_account_id' => $crmId,
            'subdomain' => $domain, 'zone' => 'ru', 'active' => true,
            'client_id' => $widget === 'workflows' ? 'workflow-client' : 'excel-client',
            'client_secret' => 'local-secret', 'redirect_uri' => 'https://platform.example/callback',
            'access_token' => $domain.'-access', 'refresh_token' => $domain.'-refresh',
            'created_at' => time(), 'expires_in' => 86400])->save();

        return $a->fresh();
    }

    private function workflow(Account $a, string $type = 'amo-button'): Workflow
    {
        $id = DB::table('workflows')->insertGetId(['user_id' => $a->user_id, 'name' => 'Private '.$a->subdomain,
            'is_active' => true, 'definition' => json_encode(['trigger' => ['type' => $type]])]);

        return Workflow::findOrFail($id);
    }

    private function jwt(Account $account, array $overrides = [], string $secret = 'local-workflow-secret', string $alg = 'HS256'): string
    {
        $encode = fn ($data) => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
        $claims = array_replace(['iss' => 'https://'.$account->subdomain.'.amocrm.ru', 'aud' => 'https://platform.example',
            'jti' => 'c29b841d-419b-4bdf-a4e3-0b5d71e84f72', 'iat' => time(), 'nbf' => time(), 'exp' => time() + 1800,
            'account_id' => (int) $account->amo_account_id, 'user_id' => 555, 'client_uuid' => 'workflow-client'], $overrides);
        $data = $encode(['alg' => $alg, 'typ' => 'JWT']).'.'.$encode($claims);

        return $data.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', $data, $secret, true)), '+/', '-_'), '=');
    }

    public function test_unsigned_requests_cannot_list_run_bulk_run_or_trigger_digital_pipeline(): void
    {
        $account = $this->connection('victim', 101);
        $workflow = $this->workflow($account);
        $this->assertGuest();
        $this->getJson('/api/amocrm/workflows/manual-buttons?subdomain=victim&lead_id=5')->assertForbidden();
        foreach (['run', 'bulk-run', 'digital-pipeline'] as $path) {
            $this->postJson('/api/amocrm/workflows/manual-buttons/'.$path,
                ['subdomain' => 'victim', 'workflow_id' => $workflow->id, 'lead_id' => 5, 'lead_ids' => [5]])->assertForbidden();
        }
        Queue::assertNothingPushed();
    }

    public function test_valid_identity_lists_only_its_workflows_and_can_run_its_own_button(): void
    {
        $a = $this->connection('tenant-a', 101);
        $b = $this->connection('tenant-b', 202);
        $workflow = $this->workflow($a);
        $this->workflow($b);
        $headers = ['X-Auth-Token' => $this->jwt($a)];
        $this->getJson('/api/amocrm/workflows/manual-buttons?subdomain=tenant-a&lead_id=5', $headers)
            ->assertOk()->assertJsonCount(1, 'workflows')->assertJsonPath('workflows.0.id', $workflow->id);
        $this->mock(WorkflowManualAmoCrmRunService::class)->shouldReceive('startButtonForLead')->once()
            ->withArgs(fn ($w, $account, $id, $input) => $w->id === $workflow->id && $account->id === $a->id && $id === 5)
            ->andReturn(['run_id' => 1, 'run_ulid' => 'local-test']);
        $this->postJson('/api/amocrm/workflows/manual-buttons/run', ['subdomain' => 'tenant-a', 'workflow_id' => $workflow->id, 'lead_id' => 5], $headers)
            ->assertStatus(202);
    }

    public function test_signed_bulk_request_runs_only_its_own_manual_workflow(): void
    {
        $a = $this->connection('tenant-a', 101);
        $workflow = $this->workflow($a, 'manual');
        $this->mock(WorkflowManualAmoCrmRunService::class)->shouldReceive('startForLead')->twice()
            ->withArgs(fn ($w, $account, $id) => $w->id === $workflow->id && $account->id === $a->id && in_array($id, [5, 6], true))
            ->andReturn(['run_id' => 1, 'run_ulid' => 'local-test']);
        $this->postJson('/api/amocrm/workflows/manual-buttons/bulk-run', [
            'workflow_id' => $workflow->id, 'lead_ids' => [5, 6, 5],
        ], ['X-Auth-Token' => $this->jwt($a)])->assertStatus(202)->assertJsonPath('count', 2);
    }

    public function test_disabled_connection_or_user_cannot_use_a_valid_signature(): void
    {
        $a = $this->connection('tenant-a', 101);
        $headers = ['X-Auth-Token' => $this->jwt($a)];
        foreach ([['active' => false], ['refresh_token' => null], ['amo_account_id' => null], ['client_id' => 'excel-client']] as $change) {
            $before = $a->getAttributes();
            DB::table('accounts')->where('id', $a->id)->update($change);
            $this->getJson('/api/amocrm/workflows/manual-buttons', $headers)->assertForbidden();
            DB::table('accounts')->where('id', $a->id)->update($before);
        }
        DB::table('users')->where('id', $a->user_id)->update(['active' => false]);
        $this->getJson('/api/amocrm/workflows/manual-buttons', $headers)->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_forged_expired_wrong_client_and_cross_tenant_jwts_fail_closed(): void
    {
        $a = $this->connection('tenant-a', 101);
        $b = $this->connection('tenant-b', 202);
        $w = $this->workflow($b);
        $tokens = [
            $this->jwt($a, [], 'wrong-secret'), $this->jwt($a, [], 'local-workflow-secret', 'none'),
            $this->jwt($a, ['exp' => time() - 1]), $this->jwt($a, ['nbf' => time() + 300]),
            $this->jwt($a, ['aud' => 'https://untrusted.example']), $this->jwt($a, ['client_uuid' => 'excel-client']),
            $this->jwt($a, ['account_id' => $b->amo_account_id]), $this->jwt($a, ['iss' => 'https://tenant-a.amocrm.ru.evil.example']),
        ];
        foreach ($tokens as $token) {
            $this->getJson('/api/amocrm/workflows/manual-buttons?lead_id=5', ['X-Auth-Token' => $token])->assertForbidden();
        }
        $headers = ['X-Auth-Token' => $this->jwt($a)];
        $this->getJson('/api/amocrm/workflows/manual-buttons?subdomain=tenant-b&lead_id=5', $headers)->assertForbidden();
        $this->postJson('/api/amocrm/workflows/manual-buttons/run', ['workflow_id' => $w->id, 'lead_id' => 5], $headers)->assertNotFound();
        Queue::assertNothingPushed();
    }

    public function test_digital_pipeline_capability_is_bound_to_the_account_and_workflow(): void
    {
        $a = $this->connection('tenant-a', 101);
        $b = $this->connection('tenant-b', 202);
        $w = $this->workflow($a, 'digital-pipeline');
        $other = $this->workflow($b, 'digital-pipeline');
        $token = app(WorkflowRequestAccess::class)->digitalPipelineToken($a, $w);
        $this->mock(WorkflowManualAmoCrmRunService::class)->shouldReceive('startDigitalPipelineForLead')->once()
            ->withArgs(fn ($workflow, $account, $lead, $input) => $workflow->id === $w->id && $account->id === $a->id && $lead === 7)
            ->andReturn(['run_id' => 1, 'run_ulid' => 'local-test']);
        $this->postJson('/api/amocrm/workflows/manual-buttons/digital-pipeline', ['workflow_id' => $token, 'lead_id' => 7])->assertOk()->assertJsonPath('queued', true);
        $this->postJson('/api/amocrm/workflows/manual-buttons/digital-pipeline', ['workflow_id' => $token, 'lead_id' => 7, 'subdomain' => 'tenant-b'])->assertForbidden();
        $this->postJson('/api/amocrm/workflows/manual-buttons/digital-pipeline', ['workflow_id' => preg_replace('/^[0-9]+/', (string) $other->id, $token), 'lead_id' => 7])->assertForbidden();
        $this->getJson('/api/amocrm/workflows/manual-buttons?source=digital-pipeline', ['X-Auth-Token' => $this->jwt($a)])
            ->assertOk()->assertJsonPath('workflows.0.dp_token', $token);
    }

    public function test_unsigned_disconnect_preserves_all_tokens(): void
    {
        $a = $this->connection('tenant-a', 101);
        $before = $a->getAttributes();
        foreach ([['subdomain' => 'tenant-a'], ['account_id' => 101, 'client_uuid' => 'workflow-client'],
            ['account_id' => 101, 'client_uuid' => 'workflow-client', 'signature' => str_repeat('a', 64)]] as $payload) {
            $this->postJson('/api/amocrm/off', $payload)->assertForbidden();
        }
        $this->assertSame($before, $a->fresh()->getAttributes());
        Mail::assertNothingQueued();
    }

    public function test_signed_disconnect_cannot_widen_scope_to_another_client_or_tenant(): void
    {
        $a = $this->connection('tenant-a', 101);
        $excel = $this->connection('tenant-a', 101, 'import-excel', $a->user_id);
        $b = $this->connection('tenant-b', 202);
        $payload = ['account_id' => 101, 'client_uuid' => 'workflow-client', 'subdomain' => 'tenant-b',
            'signature' => hash_hmac('sha256', 'workflow-client|101', 'local-workflow-secret')];
        $this->postJson('/api/amocrm/off', $payload)->assertOk()->assertJsonPath('updated', 1);
        $this->assertNull($a->fresh()->refresh_token);
        $this->assertSame('tenant-a-refresh', $excel->fresh()->refresh_token);
        $this->assertSame('tenant-b-refresh', $b->fresh()->refresh_token);
        $payload['account_id'] = 202;
        $this->postJson('/api/amocrm/off', $payload)->assertForbidden();
    }

    public function test_two_sdk_clients_keep_tokens_queries_and_services_isolated(): void
    {
        $a = $this->connection('tenant-a', 101);
        $b = $this->connection('tenant-b', 202);
        $first = new Client($a);
        $firstService = $first->service;
        $query = new \Ufee\Amo\Api\Oauth\Query($firstService);
        $second = new Client($b);
        $this->assertSame($first->service, $query->instance());
        $this->assertNotSame($first->service->getAuth('id'), $second->service->getAuth('id'));
        $this->assertSame('workflow-client', $first->service->getAuth('client_id'));
        $this->assertSame('tenant-a-access', $first->service->getOauth('access_token'));
        $this->assertSame('tenant-b-access', $second->service->getOauth('access_token'));
        $this->assertNotSame($first->service->queries, $second->service->queries);
        $this->assertSame($first->service, $first->service->leads()->instance);
        $first->service->setOauth(['access_token' => 'new-a', 'refresh_token' => 'new-a-refresh', 'created_at' => time(), 'expires_in' => 86400]);
        $this->assertSame('new-a', $a->fresh()->access_token);
        $this->assertSame('tenant-b-access', $b->fresh()->access_token);
        Http::assertNothingSent();
    }

    public function test_same_crm_widgets_do_not_share_sdk_storage_or_query_registry(): void
    {
        $a = $this->connection('tenant-a', 101);
        $b = $this->connection('tenant-a', 101, 'import-excel', $a->user_id);
        DB::table('accounts')->where('id', $b->id)->update(['client_id' => $a->client_id, 'access_token' => 'second-grant']);
        $first = new Client($a);
        $query = new \Ufee\Amo\Api\Oauth\Query($first->service);
        $second = new Client($b->fresh());
        $this->assertSame($first->service, $query->instance());
        $this->assertNotSame($first->service->queries, $second->service->queries);
        $this->assertSame('tenant-a-access', $first->service->getOauth('access_token'));
        $this->assertSame('second-grant', $second->service->getOauth('access_token'));
        $this->expectException(\LogicException::class);
        (new \App\Services\amoCRM\EloquentStorage([], $a))->getOauthData((new Client($this->connection('tenant-b', 202)))->service);
    }

    public function test_direct_cross_user_binding_is_rejected_and_notified(): void
    {
        $this->connection('tenant-a', 101);
        try {
            $this->connection('tenant-a', 101, 'import-excel');
            $this->fail('CRM owner was overwritten');
        } catch (AmoCrmOwnershipConflict) {
            $this->assertSame(1, Account::count());
        }
        Queue::assertPushed(SendAmoCrmOwnershipAlert::class, fn ($job) => $job->domain === 'tenant-a.amocrm.ru' && count($job->userIds) === 2);
    }

    public function test_browser_owner_conflict_returns_a_controlled_error_without_changing_tokens(): void
    {
        $owner = $this->connection('tenant-a', 101);
        $before = $owner->getAttributes();
        $uuid = 'b1603ba5-7720-4f7c-b664-c8852d927267';
        DB::table('users')->insert(['name' => 'Second user', 'email' => 'other@example.com', 'uuid' => $uuid, 'password' => 'local-only']);
        config(['services.amocrm.widgets.import-excel.redirect_uri' => 'https://platform.example/callback']);
        $this->mock(\App\Services\Core\PlatformTechnicalMonitor::class)->shouldReceive('amoConnectionFailed')->once();
        // Another installation can claim the CRM after the controller's early lookup.
        $guard = \Mockery::mock(new \App\Services\Integrations\AmoCrmOwnershipGuard);
        $guard->shouldReceive('save')->once()
            ->andThrow(new AmoCrmOwnershipConflict('new-tenant.amocrm.ru', [1, 2], [1]));
        $this->app->instance(\App\Services\Integrations\AmoCrmOwnershipGuard::class, $guard);
        $response = (new \App\Http\Controllers\Api\AuthController)->redirect(\Illuminate\Http\Request::create('/callback', 'GET', [
            'state' => base64_encode(json_encode(['user_uuid' => $uuid, 'widget' => 'import-excel'])),
            'client_id' => 'excel-client', 'code' => 'local-code', 'referer' => 'new-tenant.amocrm.ru',
        ]));
        parse_str((string) parse_url($response->getTargetUrl(), PHP_URL_QUERY), $query);
        $this->assertSame('409', $query['amocrm_auth_status']);
        $this->assertSame($before, $owner->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_integrity_scan_does_not_merge_legacy_owners_and_resolves_only_verified_ids(): void
    {
        $a = $this->connection('legacy-duplicate', 101);
        $b = $this->connection('legacy-other', 202);
        DB::table('accounts')->whereIn('id', [$a->id, $b->id])->update(['subdomain' => 'legacy-duplicate', 'amo_account_id' => null]);
        $valid = $this->connection('verified-legacy', 303);
        DB::table('accounts')->where('id', $valid->id)->update(['amo_account_id' => null]);
        Http::fake(['https://verified-legacy.amocrm.ru/api/v4/account' => Http::response(['id' => 303])]);
        $this->artisan('app:check-amo-ownership', ['--resolve-ids' => true])->assertSuccessful();
        $this->assertNull($a->fresh()->amo_account_id);
        $this->assertNull($b->fresh()->amo_account_id);
        $this->assertSame(303, $valid->fresh()->amo_account_id);
        $this->assertSame('verified-legacy-refresh', $valid->fresh()->refresh_token);
        Queue::assertPushed(SendAmoCrmOwnershipAlert::class, fn ($job) => $job->kind === 'conflict' && count($job->userIds) === 2);
        Http::assertSentCount(1);
    }

    public function test_ownership_alert_is_deduplicated_only_after_successful_delivery(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $job = new SendAmoCrmOwnershipAlert('conflict', 'tenant-a.amocrm.ru', [1, 2], [11, 22]);
        $job->handle();
        $job->handle();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['text'], 'tenant-a.amocrm.ru') && str_contains($request['text'], '1, 2'));
    }

    public function test_failed_alert_delivery_is_retried_instead_of_being_marked_as_sent(): void
    {
        Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => false], 503)->push(['ok' => true])]);
        $job = new SendAmoCrmOwnershipAlert('conflict', 'tenant-a.amocrm.ru', [1, 2], [11, 22]);
        try {
            $job->handle();
            $this->fail('Failed delivery must be retried');
        } catch (\RuntimeException $error) {
            $this->assertStringNotContainsString('test-bot', $error->getMessage());
        }
        $job->handle();
        $job->handle();
        Http::assertSentCount(2);
    }

    public function test_verified_id_backfill_cannot_take_another_users_crm_identity(): void
    {
        $owner = $this->connection('tenant-a', 101);
        $other = $this->connection('old-domain', 202);
        DB::table('accounts')->where('id', $other->id)->update(['amo_account_id' => null]);
        Http::fake(['https://old-domain.amocrm.ru/api/v4/account' => Http::response(['id' => 101])]);
        $this->artisan('app:check-amo-ownership', ['--resolve-ids' => true])->assertSuccessful();
        $this->assertNull($other->fresh()->amo_account_id);
        $this->assertSame(101, $owner->fresh()->amo_account_id);
        Queue::assertPushed(SendAmoCrmOwnershipAlert::class, fn ($job) => $job->kind === 'conflict');
    }

    public function test_digital_pipeline_capability_is_not_written_to_diagnostic_logs(): void
    {
        $a = $this->connection('tenant-a', 101);
        $w = $this->workflow($a, 'digital-pipeline');
        $token = app(WorkflowRequestAccess::class)->digitalPipelineToken($a, $w);
        $controller = new \App\Http\Controllers\Api\WorkflowManualAmoCrmController;
        $preview = (new \ReflectionMethod($controller, 'payloadPreview'))->invoke($controller, ['settings' => ['workflow_id' => $token]]);
        $this->assertSame($w->id, $preview['settings.workflow_id']);
        $this->assertStringNotContainsString($token, json_encode($preview));
    }
}

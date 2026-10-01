<?php

namespace Tests\Unit\Integrations;

use App\Helpers\Traits\SyncAmoCRMPage;
use App\Http\Controllers\Api\AuthController;
use App\Models\Core\Account;
use App\Models\User;
use App\Services\Integrations\AmoCrmWidgetInstallationService;
use App\Services\Core\PlatformTechnicalMonitor;
use App\Services\ImportExcel\ExcelConnectionAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExcelOAuthIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'excel_oauth_test',
            'database.connections.excel_oauth_test' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'services.amocrm.client_id' => 'platform-client',
            'services.amocrm.client_secret' => 'platform-secret',
            'services.amocrm.redirect_uri' => 'https://platform.example/api/amocrm/redirect',
            'services.amocrm.widgets.import-excel.client_id' => 'excel-client',
            'services.amocrm.widgets.import-excel.client_secret' => 'excel-secret',
            'services.amocrm.widgets.import-excel.redirect_uri' => 'https://platform.example/api/amocrm/install/excel',
        ]);
        DB::purge('excel_oauth_test');
        Http::preventStrayRequests();
        Http::fake();
        Notification::fake();
        Queue::fake();
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid');
        });
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('widget')->nullable();
            $table->string('oauth_connector')->nullable();
            $table->boolean('active')->default(false);
            foreach (['subdomain', 'zone', 'client_id', 'client_secret', 'access_token', 'refresh_token', 'code', 'redirect_uri'] as $field) {
                $table->string($field)->nullable();
            }
        });
        DB::table('users')->insert([
            ['id' => 1, 'uuid' => 'ba2c3f10-3250-4d86-b36a-8f924fdaaa01'],
            ['id' => 2, 'uuid' => 'ba2c3f10-3250-4d86-b36a-8f924fdaaa02'],
        ]);
    }

    public function test_excel_fallback_is_disabled_in_configuration(): void
    {
        $this->assertFalse(config('services.amocrm.widgets.import-excel.fallback_to_platform_credentials'));
    }

    public function test_excel_never_reuses_other_widgets_or_another_users_connection(): void
    {
        $shared = $this->account('default', 'platform-client');
        $workflow = $this->account('workflows', 'workflow-client');
        $foreign = $this->account('import-excel', 'excel-client', ['user_id' => 2]);
        $before = [$shared->getAttributes(), $workflow->getAttributes(), $foreign->getAttributes()];
        $user = User::findOrFail(1);

        $this->assertNull($user->resolveAmoAccountForWidget('import-excel'));
        $slot = $user->resolveAmoAccountForWidget('import-excel', true);
        $this->assertSame('import-excel', $slot->widget);
        $this->assertSame(1, $slot->user_id);
        $this->assertFalse($slot->fresh()->active);
        foreach (['subdomain', 'client_id', 'client_secret', 'access_token', 'refresh_token'] as $key) {
            $this->assertNull($slot->{$key});
        }
        $this->assertSame($slot->id, $user->resolveAmoAccountForWidget('import-excel', true)->id);
        $this->assertSame($before, [$shared->fresh()->getAttributes(), $workflow->fresh()->getAttributes(), $foreign->fresh()->getAttributes()]);
    }

    public function test_excel_keeps_its_own_connection_including_after_reset(): void
    {
        $excel = $this->account('import-excel', 'excel-client');
        $shared = $this->account('default', 'platform-client');
        $before = $shared->getAttributes();
        $user = User::findOrFail(1);

        $this->assertSame($excel->id, $user->resolveAmoAccountForWidget('import-excel')->id);
        $page = $this->page();
        (new \ReflectionMethod($page, 'resetAmoCrmAccount'))->invoke($page, $excel);
        $this->assertSame($excel->id, $user->resolveAmoAccountForWidget('import-excel', true)->id);
        $resolved = $user->resolveAmoAccountForWidget('import-excel');
        $this->assertTrue($resolved === null || $resolved->id === $excel->id);
        $this->assertFalse($excel->fresh()->active);
        $this->assertNull($excel->fresh()->access_token);
        $this->assertSame($before, $shared->fresh()->getAttributes());
    }

    public static function identityCredentials(): array
    {
        return ['client ID' => ['client_id'], 'client secret' => ['client_secret']];
    }

    #[DataProvider('identityCredentials')]
    public function test_wrong_saved_credentials_require_excel_reconnection(string $key): void
    {
        $excel = $this->account('import-excel', 'excel-client', [$key => 'another-widget-value']);
        $before = $excel->getAttributes();
        $this->account('default', 'platform-client');
        $user = User::findOrFail(1);

        $this->assertNull($user->resolveAmoAccountForWidget('import-excel'));
        $this->assertSame($excel->id, $user->resolveAmoAccountForWidget('import-excel', true)->id);
        $this->assertSame($before, $excel->fresh()->getAttributes());
    }

    public function test_excel_cannot_be_selected_as_a_shared_source(): void
    {
        $this->account('import-excel', 'excel-client');
        $user = User::findOrFail(1);
        $this->assertNull($user->resolveAmoAccountForWidget('tilda'));
        $controller = new AuthController;
        $method = new \ReflectionMethod($controller, 'resolveSharedSourceAccount');
        $this->assertNull($method->invoke($controller, $user));

        $shared = $this->account('default', 'platform-client');
        $this->account('import-excel', 'excel-client');
        $this->assertSame($shared->id, $user->resolveAmoAccountForWidget('tilda')->id);
        $this->assertSame($shared->id, $method->invoke($controller, $user)->id);
    }

    public function test_reauthorizing_a_foreign_connector_discards_its_old_tokens_before_relabelling(): void
    {
        $account = $this->account('import-excel', 'platform-client', ['client_secret' => 'platform-secret']);
        $before = $account->getAttributes();
        ExcelConnectionAccess::prepareForAuthorization($account);

        $this->assertNull($account->access_token);
        $this->assertNull($account->refresh_token);
        $this->assertNull($account->expires_in);
        $this->assertNull($account->created_at);
        $this->assertFalse($account->active);
        $this->assertSame($before, $account->fresh()->getAttributes());
    }

    public function test_reauthorizing_the_same_excel_connector_preserves_its_tokens(): void
    {
        $account = $this->account('import-excel', 'excel-client');
        $before = $account->getAttributes();
        ExcelConnectionAccess::prepareForAuthorization($account);

        $this->assertSame($before, $account->getAttributes());
    }

    public function test_legacy_fallback_with_missing_client_id_is_unchanged(): void
    {
        $legacy = $this->account('tilda', 'platform-client', ['client_id' => null]);
        $this->assertSame($legacy->id, User::findOrFail(1)->resolveAmoAccountForWidget('distribution')->id);
    }

    public function test_authorization_screen_and_callback_use_only_excel_configuration(): void
    {
        $account = $this->account('import-excel', 'another-client', ['client_secret' => 'another-secret']);
        $this->assertSame('excel-client', $this->page()->clientId($account));
        $this->assertSame([
            'client_secret' => 'excel-secret',
            'redirect_uri' => 'https://platform.example/api/amocrm/install/excel',
        ], $this->callbackConfig($account));

        config([
            'services.amocrm.widgets.import-excel.client_id' => null,
            'services.amocrm.widgets.import-excel.client_secret' => null,
            'services.amocrm.widgets.import-excel.redirect_uri' => null,
        ]);
        $this->assertSame('', $this->page()->clientId($account));
        $this->assertSame(['client_secret' => '', 'redirect_uri' => ''], $this->callbackConfig($account));
    }

    public static function credentials(): array
    {
        return ['client ID' => ['client_id'], 'client secret' => ['client_secret'], 'redirect URI' => ['redirect_uri']];
    }

    #[DataProvider('credentials')]
    public function test_installer_rejects_missing_excel_credentials_without_sending_any_request(string $key): void
    {
        config([
            'services.amocrm.widgets.import-excel.'.$key => ' ',
            'services.amocrm.widgets.import-excel.fallback_to_platform_credentials' => true,
        ]);
        try {
            app(AmoCrmWidgetInstallationService::class)->install('test-code', 'client.amocrm.ru', 'import-excel');
            $this->fail('Missing Excel credentials must fail closed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('amoCRM import-excel '.$key.' is not configured.', $exception->getMessage());
        }
        Http::assertNothingSent();
        $this->assertSame(0, Account::count());
    }

    #[DataProvider('credentials')]
    public function test_browser_callback_rejects_missing_excel_configuration_before_changing_credentials(string $key): void
    {
        $excel = $this->account('import-excel', 'excel-client');
        $before = $excel->getAttributes();
        config(['services.amocrm.widgets.import-excel.'.$key => '']);
        $this->mock(PlatformTechnicalMonitor::class)->shouldReceive('amoConnectionFailed')->once();
        $response = (new AuthController)->redirect(Request::create('/api/amocrm/redirect', 'GET', [
            'state' => base64_encode(json_encode(['user_uuid' => User::findOrFail(1)->uuid, 'widget' => 'import-excel'])),
            'client_id' => 'excel-client', 'code' => 'test-code', 'referer' => 'client.amocrm.ru',
        ]));
        parse_str((string) parse_url($response->getTargetUrl(), PHP_URL_QUERY), $query);

        $this->assertSame('422', $query['amocrm_auth_status']);
        $this->assertSame('error', $query['amocrm_auth']);
        $this->assertStringContainsString($key, $query['amocrm_auth_message']);
        $this->assertSame($before, $excel->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_browser_callback_rejects_a_foreign_client_id(): void
    {
        $excel = $this->account('import-excel', 'excel-client');
        $before = $excel->getAttributes();
        $this->mock(PlatformTechnicalMonitor::class)->shouldReceive('amoConnectionFailed')->once();
        $response = (new AuthController)->redirect(Request::create('/api/amocrm/redirect', 'GET', [
            'state' => base64_encode(json_encode(['user_uuid' => User::findOrFail(1)->uuid, 'widget' => 'import-excel'])),
            'client_id' => 'platform-client', 'code' => 'test-code', 'referer' => 'client.amocrm.ru',
        ]));
        parse_str((string) parse_url($response->getTargetUrl(), PHP_URL_QUERY), $query);

        $this->assertSame('422', $query['amocrm_auth_status']);
        $this->assertStringContainsString('другой amoCRM widget', $query['amocrm_auth_message']);
        $this->assertSame($before, $excel->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_excel_isolation_does_not_disable_the_single_crm_guard(): void
    {
        $shared = $this->account('default', 'platform-client');
        $before = $shared->getAttributes();
        try {
            $this->account('import-excel', 'excel-client', ['subdomain' => 'another-crm']);
            $this->fail('Excel isolation must not authorize rebinding an existing platform account.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('subdomain', $exception->errors());
        }
        $this->assertSame($before, $shared->fresh()->getAttributes());
        $this->assertSame(0, Account::where('widget', 'import-excel')->count());
    }

    private function account(string $widget, string $clientId, array $extra = []): Account
    {
        $account = (new Account)->forceFill(array_merge([
            'user_id' => 1, 'widget' => $widget, 'subdomain' => 'client', 'zone' => 'ru',
            'client_id' => $clientId, 'client_secret' => 'excel-secret', 'active' => true,
            'access_token' => 'test-access', 'refresh_token' => 'test-refresh',
            'redirect_uri' => 'https://stored.example/callback',
        ], $extra));
        $account->save();

        return $account->fresh();
    }

    private function callbackConfig(Account $account): array
    {
        $controller = new AuthController;

        return (new \ReflectionMethod($controller, 'resolveOauthConfigForWidget'))
            ->invoke($controller, 'import-excel', User::findOrFail(1), $account);
    }

    private function page(): object
    {
        return new class
        {
            use SyncAmoCRMPage;

            public function clientId(Account $account): string
            {
                return $this->resolveOauthClientId('import-excel', $account);
            }
        };
    }
}

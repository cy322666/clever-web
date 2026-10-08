<?php

namespace Tests\Feature\Integrations;

use App\Services\Billing\WidgetSubscriptionAccessService;
use App\Services\Integrations\AmoCrmWidgetInstallationService;
use App\Services\Integrations\IntegrationProvisioningService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AmoCrmOwnershipPostgresTest extends TestCase
{
    private ?string $schema = null;

    private ?string $directory = null;

    protected function setUp(): void
    {
        parent::setUp();
        $socket = getenv('TENANT_TEST_PG_SOCKET');
        if (! $socket || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires an explicit local PostgreSQL test socket and pcntl.');
        }
        if (! preg_match('#^/(?:private/)?tmp/#', (string) realpath($socket))) {
            $this->fail('Only a disposable PostgreSQL socket under /tmp is allowed.');
        }
        config(['database.default' => 'tenant_race_test', 'database.connections.tenant_race_test' => [
            'driver' => 'pgsql', 'host' => $socket, 'port' => getenv('TENANT_TEST_PG_PORT') ?: 59143,
            'database' => 'postgres', 'username' => get_current_user(), 'password' => '',
            'charset' => 'utf8', 'prefix' => '', 'sslmode' => 'disable',
        ], 'cache.default' => 'array']);
        DB::purge('tenant_race_test');
        $this->schema = 'tenant_test_'.bin2hex(random_bytes(8));
        DB::statement('CREATE SCHEMA '.$this->schema);
        config(['database.connections.tenant_race_test.search_path' => $this->schema]);
        DB::purge('tenant_race_test');
        DB::unprepared(<<<'SQL'
            CREATE TABLE users (
                id bigserial PRIMARY KEY, uuid uuid UNIQUE, name varchar(255), email varchar(255) UNIQUE,
                password varchar(255), active boolean DEFAULT true, email_verified_at timestamp,
                remember_token varchar(100), created_at timestamp, updated_at timestamp
            );
            CREATE TABLE accounts (
                id bigserial PRIMARY KEY, user_id bigint REFERENCES users(id), widget varchar(255) NOT NULL DEFAULT 'default',
                oauth_connector varchar(255), amo_account_id bigint, subdomain varchar(255), zone varchar(255),
                code text, access_token text, refresh_token text, client_id varchar(255), client_secret varchar(255),
                active boolean DEFAULT false, redirect_uri varchar(255), expires_in integer, created_at integer,
                UNIQUE(user_id, widget), UNIQUE(amo_account_id, widget)
            );
            CREATE TABLE apps (
                id bigserial PRIMARY KEY, user_id bigint REFERENCES users(id), name varchar(255),
                resource_name varchar(255), setting_id bigint, status integer DEFAULT 0,
                expires_tariff_at date, installed_at timestamp, created_at timestamp, updated_at timestamp,
                UNIQUE(user_id, name)
            );
            SQL);
        foreach (['alpha', 'beta'] as $user) {
            DB::table('users')->insert(['name' => $user, 'email' => $user.'@example.com', 'password' => 'local-only']);
        }
        foreach (['workflows', 'import-excel'] as $widget) {
            config(["services.amocrm.widgets.$widget.client_id" => "$widget-local-client",
                "services.amocrm.widgets.$widget.client_secret" => 'local-secret',
                "services.amocrm.widgets.$widget.redirect_uri" => 'https://platform.example/callback']);
        }
        $this->mock(IntegrationProvisioningService::class)->shouldReceive('syncCatalogForUser')->andReturnNull();
        $this->mock(WidgetSubscriptionAccessService::class)->shouldReceive('ensureTrialForWidget')->andReturnNull();
        Artisan::shouldReceive('call')->andReturn(0);
        Queue::fake();
        $this->directory = sys_get_temp_dir().'/'.$this->schema;
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        if ($this->schema) {
            DB::purge('tenant_race_test');
            DB::statement('DROP SCHEMA '.$this->schema.' CASCADE');
        }
        if ($this->directory) {
            foreach (glob($this->directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    public static function races(): array
    {
        return [
            'different widgets, same CRM' => ['import-excel', 'alpha', 'fresh-crm', 99001, 2, 2],
            'same widget, same CRM' => ['workflows', 'alpha', 'fresh-crm', 99001, 2, 1],
            'same owner, different CRMs' => ['import-excel', 'beta', 'other-crm', 99002, 1, 1],
        ];
    }

    #[DataProvider('races')]
    public function test_simultaneous_installations_preserve_single_ownership(
        string $firstWidget, string $firstUser, string $firstDomain, int $firstCrmId, int $successes, int $rows,
    ): void {
        DB::disconnect('tenant_race_test');
        $workers = [[$firstWidget, $firstUser, $firstDomain, $firstCrmId], ['workflows', 'beta', 'fresh-crm', 99001]];
        $pids = [];
        foreach ($workers as $index => [$widget, $employee, $domain, $crmId]) {
            $pid = pcntl_fork();
            if ($pid < 0) {
                $this->fail('Cannot fork ownership test worker.');
            }
            if ($pid === 0) {
                DB::purge('tenant_race_test');
                DB::statement("SET statement_timeout = '20s'");
                DB::statement("SET lock_timeout = '10s'");
                Http::preventStrayRequests();
                Http::fake([
                    "https://$domain.amocrm.ru/oauth2/access_token" => Http::response(['access_token' => 'local-access', 'refresh_token' => 'local-refresh']),
                    "https://$domain.amocrm.ru/api/v4/account" => Http::response(['id' => $crmId, 'current_user_id' => 777]),
                    "https://$domain.amocrm.ru/api/v4/users/777" => Http::response(['id' => 777, 'email' => $employee.'@example.com']),
                ]);
                try {
                    file_put_contents($this->directory.'/ready-'.$index, 'ready');
                    $deadline = microtime(true) + 10;
                    while (count(glob($this->directory.'/ready-*')) < 2) {
                        if (microtime(true) > $deadline) {
                            throw new \RuntimeException('Test barrier timed out');
                        }
                        usleep(1000);
                    }
                    $result = app(AmoCrmWidgetInstallationService::class)->install('local-code', "$domain.amocrm.ru", $widget);
                    $out = ['ok' => true, 'user_id' => $result['user']->id];
                } catch (\Throwable $error) {
                    $out = ['ok' => false, 'error' => $error::class];
                }
                file_put_contents($this->directory.'/result-'.$index, json_encode($out));
                exit(0);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
        DB::purge('tenant_race_test');
        $results = array_map(fn ($index) => json_decode(file_get_contents($this->directory.'/result-'.$index), true), [0, 1]);
        $this->assertCount($successes, array_filter($results, fn ($result) => $result['ok']), json_encode($results));
        $this->assertSame($rows, DB::table('accounts')->count());
        $this->assertSame(1, DB::table('accounts')->distinct()->count('user_id'));
        $this->assertSame(1, DB::table('accounts')->distinct()->count('amo_account_id'));
    }
}

<?php

namespace Tests\Unit\Integrations;

use App\Helpers\Traits\SyncAmoCRMPage;
use App\Http\Controllers\Api\AuthController;
use App\Models\Core\Account;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class YClientsOAuthRoutingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'yc_oauth_test',
            'database.connections.yc_oauth_test' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'services.amocrm.client_id' => 'shared-client',
            'services.amocrm.client_secret' => 'shared-secret',
            'services.amocrm.redirect_uri' => 'https://platform.example/api/amocrm/redirect',
            'services.amocrm.widgets.yclients.use_shared_connector' => true,
            'services.amocrm.widgets.yclients.client_id' => 'yc-client',
            'services.amocrm.widgets.yclients.client_secret' => 'yc-secret',
            'services.amocrm.widgets.yclients.redirect_uri' => 'https://platform.example/api/amocrm/install/yclients',
        ]);
        DB::purge('yc_oauth_test');
        Http::preventStrayRequests();
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('widget');
            $table->string('oauth_connector')->nullable();
            $table->boolean('active')->default(false);
            foreach (['subdomain', 'zone', 'client_id', 'client_secret', 'access_token', 'refresh_token', 'code', 'redirect_uri'] as $field) {
                $table->string($field)->nullable();
            }
        });
        DB::table('users')->insert([['id' => 1], ['id' => 2]]);
    }

    public function test_legacy_uses_shared_credentials_despite_a_newer_excel_connection(): void
    {
        $shared = $this->account('default', 'shared-client');
        $this->account('import-excel', 'excel-client');
        $this->account('default', 'shared-client', ['user_id' => 2]);

        $this->assertSame($shared->id, User::findOrFail(1)->resolveAmoAccountForWidget('yclients')->id);
        $this->assertConnector($shared, 'shared-client', 'shared-secret', 'https://platform.example/api/amocrm/redirect');
    }

    public function test_existing_yclients_connection_keeps_its_id_and_tokens(): void
    {
        $this->account('default', 'shared-client');
        $yc = $this->account('yclients', 'shared-client', ['oauth_connector' => Account::CONNECTOR_SHARED]);
        $before = $yc->getAttributes();
        $this->account('workflows', 'flow-client');

        $this->assertSame($yc->id, User::findOrFail(1)->resolveAmoAccountForWidget('yclients')->id);
        $this->assertSame($before, $yc->fresh()->getAttributes());
    }

    public function test_no_common_auth_does_not_reuse_another_widgets_tokens(): void
    {
        $this->account('import-excel', 'excel-client');
        $user = User::findOrFail(1);

        $this->assertNull($user->resolveAmoAccountForWidget('yclients'));
        $created = $user->resolveAmoAccountForWidget('yclients', true);
        $this->assertFalse($created->fresh()->active);
        $this->assertNull($created->access_token);
        $this->assertConnector($created, 'shared-client', 'shared-secret', 'https://platform.example/api/amocrm/redirect');
    }

    public function test_marketplace_mode_survives_reset_without_switching_to_shared(): void
    {
        $shared = $this->account('default', 'shared-client');
        $yc = $this->account('yclients', 'yc-client', ['oauth_connector' => Account::CONNECTOR_WIDGET]);
        $page = $this->page();
        (new \ReflectionMethod($page, 'resetAmoCrmAccount'))->invoke($page, $yc);

        $account = User::findOrFail(1)->resolveAmoAccountForWidget('yclients');
        $this->assertSame($yc->id, $account->id);
        $this->assertFalse($account->active);
        $this->assertSame(Account::CONNECTOR_WIDGET, $account->oauth_connector);
        $this->assertSame('token', $shared->fresh()->access_token);
        $this->assertConnector($account, 'yc-client', 'yc-secret', 'https://platform.example/api/amocrm/install/yclients');
    }

    public function test_widget_reconnect_cannot_fall_back_to_shared_keys_when_widget_keys_are_missing(): void
    {
        $yc = $this->account('yclients', 'yc-client', ['oauth_connector' => Account::CONNECTOR_WIDGET]);
        config(['services.amocrm.widgets.yclients.client_id' => null, 'services.amocrm.widgets.yclients.client_secret' => null]);
        $this->assertConnector($yc, '', '', 'https://platform.example/api/amocrm/install/yclients');
    }

    public function test_migration_marks_existing_yclients_shared_without_changing_any_tokens(): void
    {
        Schema::table('accounts', fn (Blueprint $table) => $table->dropColumn('oauth_connector'));
        $yc = $this->account('yclients', 'shared-client');
        $other = $this->account('import-excel', 'excel-client');
        $before = $yc->getAttributes();
        $migration = require database_path('migrations/2026_09_28_170000_add_oauth_connector_to_accounts.php');
        $migration->up();

        $this->assertSame(Account::CONNECTOR_SHARED, $yc->fresh()->oauth_connector);
        $this->assertSame($before, array_intersect_key($yc->fresh()->getAttributes(), $before));
        $this->assertNull($other->fresh()->oauth_connector);
    }

    private function account(string $widget, string $clientId, array $extra = []): Account
    {
        $account = (new Account)->forceFill(array_merge([
            'user_id' => 1, 'widget' => $widget, 'subdomain' => 'client', 'zone' => 'ru',
            'client_id' => $clientId, 'active' => true, 'access_token' => 'token',
            'refresh_token' => 'refresh', 'client_secret' => 'stored-secret',
            'redirect_uri' => 'https://stored.example/callback',
        ], $extra));
        $account->save();

        return $account->fresh();
    }

    private function assertConnector(Account $account, string $clientId, string $secret, string $redirect): void
    {
        $this->assertSame($clientId, $this->page()->clientId($account));
        $controller = new AuthController;
        $config = (new \ReflectionMethod($controller, 'resolveOauthConfigForWidget'))
            ->invoke($controller, 'yclients', User::findOrFail(1), $account);
        $this->assertSame($secret, $config['client_secret']);
        $this->assertSame($redirect, $config['redirect_uri']);
    }

    private function page(): object
    {
        return new class {
            use SyncAmoCRMPage;

            public function clientId(Account $account): string
            {
                return $this->resolveOauthClientId('yclients', $account);
            }
        };
    }
}

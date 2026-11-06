<?php

namespace Tests\Unit\Integrations;

use App\Models\Core\Account;
use App\Models\Integrations\Tilda\Setting;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TildaSharedAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'tilda_shared_auth_test',
            'database.connections.tilda_shared_auth_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
            ],
        ]);
        DB::purge('tilda_shared_auth_test');

        Schema::create('users', fn (Blueprint $table) => $table->id());
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('widget')->nullable();
            $table->boolean('active')->default(false);
            foreach (['subdomain', 'zone', 'access_token', 'refresh_token'] as $field) {
                $table->string($field)->nullable();
            }
        });
        Schema::create('tilda_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });

        DB::table('users')->insert(['id' => 1]);
        DB::table('accounts')->insert([
            [
                'id' => 1, 'user_id' => 1, 'widget' => Account::DEFAULT_WIDGET,
                'active' => false, 'subdomain' => 'shared', 'zone' => 'ru',
                'access_token' => null, 'refresh_token' => null,
            ],
            [
                'id' => 2, 'user_id' => 1, 'widget' => 'tilda',
                'active' => true, 'subdomain' => 'legacy-tilda', 'zone' => 'ru',
                'access_token' => 'legacy-token', 'refresh_token' => 'legacy-refresh',
            ],
        ]);
        DB::table('tilda_settings')->insert([
            'id' => 1, 'user_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_tilda_uses_default_account_even_when_legacy_tilda_oauth_is_active(): void
    {
        $setting = Setting::findOrFail(1);

        $this->assertSame(Account::DEFAULT_WIDGET, config('integrations.definitions.tilda.amo_widget'));
        $this->assertSame(Account::DEFAULT_WIDGET, $setting->amoWidget());
        $this->assertSame(1, $setting->amoAccount(false, 'tilda')?->id);
        $this->assertSame(1, User::findOrFail(1)->resolveAmoAccountForWidget(Account::DEFAULT_WIDGET)?->id);
        $this->assertSame(1, User::findOrFail(1)->resolveAmoAccountForWidget('tilda')?->id);
    }

    public function test_missing_default_account_does_not_reuse_legacy_tilda_oauth(): void
    {
        DB::table('accounts')->where('id', 1)->delete();

        $account = Setting::findOrFail(1)->amoAccount(true, 'tilda');

        $this->assertNull($account);
        $this->assertSame(1, DB::table('accounts')->count());
    }
}

<?php

namespace Tests\Feature\Finder;

use App\Models\App;
use App\Models\Integrations\Finder\Setting;
use App\Services\Integrations\IntegrationProvisioningService;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinderDatabase;
use Tests\TestCase;

class SharedAuthorizationTest extends TestCase
{
    public function test_finder_prefers_platform_authorization_even_when_own_connection_exists(): void
    {
        $setting = FinderDatabase::prepare();
        DB::table('accounts')->insert(['id' => 2, 'user_id' => 1, 'widget' => 'finder', 'access_token' => 'unused-test', 'refresh_token' => 'unused-test']);

        $this->assertSame(1, $setting->amoAccount(true, 'finder')->id);
    }

    public function test_provisioning_creates_no_finder_account_without_platform_authorization(): void
    {
        FinderDatabase::prepare();
        Setting::query()->delete();
        DB::table('apps')->where('name', 'finder')->update(['setting_id' => null]);
        DB::table('accounts')->delete();

        $app = app(IntegrationProvisioningService::class)->ensureSettingForApp(App::where('name', 'finder')->sole());

        $this->assertSame(0, DB::table('accounts')->count());
        $this->assertNotNull($app->setting_id);
        $this->assertNull(Setting::sole()->account_id);
    }

    public function test_provisioning_reuses_platform_connection(): void
    {
        FinderDatabase::prepare();
        Setting::query()->delete();
        DB::table('apps')->where('name', 'finder')->update(['setting_id' => null]);
        app(IntegrationProvisioningService::class)->ensureSettingForApp(App::where('name', 'finder')->sole());

        $this->assertSame(1, DB::table('accounts')->count());
        $this->assertSame(1, Setting::sole()->account_id);
    }
}

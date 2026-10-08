<?php

namespace Tests\Feature\Integrations;

use App\Models\App;
use App\Models\User;
use App\Services\Integrations\IntegrationReturnTarget;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FinderDatabase;
use Tests\TestCase;

class IntegrationReturnTargetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FinderDatabase::prepare();
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
        foreach (config('integrations.definitions') as $widget => $definition) {
            if (App::where('user_id', 1)->where('name', $widget)->exists()) {
                continue;
            }
            $resource = $definition['resource'];
            $model = $resource::getModel();
            $table = (new $model)->getTable();
            Schema::create($table, function (Blueprint $table): void {
                $table->id();
                $table->integer('user_id');
                $table->integer('account_id');
            });
            DB::table($table)->insert(['id' => 55, 'user_id' => 1, 'account_id' => 1]);
            DB::table('apps')->insert(['user_id' => 1, 'name' => $widget, 'resource_name' => $resource,
                'setting_id' => 55, 'status' => App::STATE_ACTIVE]);
        }
    }

    public function test_every_catalog_widget_returns_to_its_own_settings_or_workflow_list(): void
    {
        $this->actingAs(User::findOrFail(1));
        $targets = app(IntegrationReturnTarget::class);
        foreach (config('integrations.definitions') as $widget => $definition) {
            $integration = App::where('user_id', 1)->where('name', $widget)->sole();
            $target = $targets->forUser(1, $widget);
            $this->assertSame(route('integrations.open', ['app' => $integration->id]), $target);
            $expected = ($definition['requires_setting'] ?? true)
                ? $definition['resource']::getUrl('edit', ['record' => $integration->setting_id], panel: 'app')
                : $definition['resource']::getUrl($definition['open_page'], panel: 'app');
            $this->get($target)->assertRedirect($expected);
        }
    }

    public function test_fast_registration_for_an_existing_user_preserves_the_target_after_login(): void
    {
        $target = app(IntegrationReturnTarget::class)->forUser(1, 'tilda');
        $this->get('/api/amocrm/widget?'.http_build_query(['email' => 'finder@example.test', 'widget' => 'tilda']))
            ->assertRedirect($target);
        $this->assertGuest();
        $this->get($target)->assertRedirect(route('filament.app.auth.login'))->assertSessionHas('url.intended', $target);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_unknown_widgets_and_missing_users_never_select_another_tenant(): void
    {
        $targets = app(IntegrationReturnTarget::class);
        $this->assertNull($targets->forUser(0, 'sqns'));
        $this->assertNull($targets->forUser(2, 'sqns'));
        $this->assertNull($targets->forUser(1, 'retired-widget'));
    }
}

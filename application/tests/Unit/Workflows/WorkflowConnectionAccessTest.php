<?php

namespace Tests\Unit\Workflows;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\WorkflowManualAmoCrmController;
use App\Models\Core\Account;
use App\Models\User;
use App\Models\Workflows\Workflow;
use App\Services\Workflows\WorkflowConnectionAccess;
use App\Services\Workflows\WorkflowSubscriptionAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Validation\ValidationException;
use Leek\FilamentWorkflows\Exceptions\NonRetryableWorkflowException;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowConnectionAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowListDatabase::prepare();
        Schema::table('accounts', fn (Blueprint $table) => $table->string('zone')->nullable());
        Schema::create('apps', function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id');
            $table->string('name');
            $table->integer('status')->default(0);
            $table->date('expires_tariff_at')->nullable();
            $table->integer('setting_id')->nullable();
            $table->string('resource_name')->nullable();
            $table->dateTime('installed_at')->nullable();
            $table->timestamps();
        });
        DB::table('apps')->insert([
            'user_id' => 2,
            'name' => 'workflows',
            'status' => \App\Models\App::STATE_ACTIVE,
            'expires_tariff_at' => now()->addDays(7)->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->app->forgetInstance(\App\Services\Workflows\WorkflowSubscriptionAccess::class);
        Http::preventStrayRequests();
    }

    public function test_activation_accepts_any_active_connection_for_the_same_amo_domain(): void
    {
        $this->assertFalse(WorkflowConnectionAccess::hasActiveConnection(2));
        $id = DB::table('accounts')->insertGetId(['user_id'=>2,'widget'=>'workflows','subdomain'=>'client','active'=>false,'refresh_token'=>'test']);
        $this->assertFalse(WorkflowConnectionAccess::hasActiveConnection(2));
        DB::table('accounts')->where('id',$id)->update(['active'=>true,'refresh_token'=>'']);
        $this->assertFalse(WorkflowConnectionAccess::hasActiveConnection(2));
        DB::table('accounts')->where('id',$id)->update(['refresh_token'=>'test','widget'=>'tilda']);
        $this->assertTrue(WorkflowConnectionAccess::hasActiveConnection(2));
        $this->assertSame('tilda', User::findOrFail(2)->resolveAmoAccountForWidget('workflows')?->widget);
        DB::table('accounts')->insert(['user_id'=>2,'widget'=>'default','subdomain'=>'different','active'=>true,'refresh_token'=>'old']);
        $this->assertFalse(WorkflowConnectionAccess::hasActiveConnection(2));
    }

    public function test_even_manual_workflows_cannot_be_activated_by_bypassing_the_page(): void
    {
        $workflow = (new Workflow)->forceFill(['name'=>'Test','user_id'=>2,'is_active'=>false,'definition'=>[
            'trigger'=>['type'=>'manual'], 'actions'=>[['id'=>'delay','type'=>'workflow_delay','config'=>['seconds'=>1]]],
        ]]);
        $workflow->save();
        $workflow->is_active = true;
        $this->expectException(ValidationException::class);
        $workflow->save();
    }

    public function test_oauth_guard_has_no_owner_exception(): void
    {
        DB::table('accounts')->insert(['user_id'=>1,'widget'=>'default','subdomain'=>'client','active'=>true,'refresh_token'=>'test']);
        $method = new \ReflectionMethod(AuthController::class, 'validateAmoSubdomainAllowedForUser');
        $controller = new AuthController;
        $this->assertNull($method->invoke($controller, User::findOrFail(1), 'CLIENT'));
        $this->assertStringContainsString('запрещено', $method->invoke($controller, User::findOrFail(1), 'different'));
    }

    public function test_manual_workflow_can_activate_after_connection_and_disable_after_disconnect(): void
    {
        DB::table('accounts')->insert(['user_id'=>2,'widget'=>'workflows','subdomain'=>'client','active'=>true,'refresh_token'=>'test']);
        $workflow = (new Workflow)->forceFill(['name'=>'Test','user_id'=>2,'is_active'=>true,'definition'=>[
            'trigger'=>['type'=>'manual'], 'actions'=>[['id'=>'delay','type'=>'workflow_delay','config'=>['seconds'=>1]]],
        ]]);
        $workflow->save();
        $this->assertTrue($workflow->fresh()->is_active);
        DB::table('accounts')->update(['active'=>false]);
        $workflow->is_active = false;
        $workflow->save();
        $this->assertFalse($workflow->fresh()->is_active);
    }

    public function test_expired_period_keeps_draft_editing_but_blocks_activation(): void
    {
        DB::table('apps')->where('user_id', 2)->update([
            'expires_tariff_at' => now()->subDay()->toDateString(),
        ]);
        DB::table('accounts')->insert(['user_id'=>2,'widget'=>'workflows','subdomain'=>'client','active'=>true,'refresh_token'=>'test']);

        $workflow = (new Workflow)->forceFill(['name'=>'Черновик','user_id'=>2,'is_active'=>false,'definition'=>[
            'trigger'=>['type'=>'manual'], 'actions'=>[['id'=>'delay','type'=>'workflow_delay','config'=>['seconds'=>1]]],
        ]]);
        $workflow->save();
        $workflow->name = 'Черновик изменён';
        $workflow->save();
        $this->assertSame('Черновик изменён', $workflow->fresh()->name);

        $workflow->is_active = true;
        $this->expectException(ValidationException::class);
        $workflow->save();
    }

    public function test_expired_period_disables_an_active_workflow_before_execution(): void
    {
        DB::table('accounts')->insert(['user_id'=>2,'widget'=>'workflows','subdomain'=>'client','active'=>true,'refresh_token'=>'test']);
        $workflow = (new Workflow)->forceFill(['name'=>'Активный поток','user_id'=>2,'is_active'=>true,'definition'=>[
            'trigger'=>['type'=>'manual'], 'actions'=>[['id'=>'delay','type'=>'workflow_delay','config'=>['seconds'=>1]]],
        ]]);
        $workflow->save();
        DB::table('apps')->where('user_id', 2)->update([
            'expires_tariff_at' => now()->subDay()->toDateString(),
        ]);

        try {
            app(WorkflowSubscriptionAccess::class)->assertCanExecute(2);
            $this->fail('Просроченный поток не должен запускаться.');
        } catch (NonRetryableWorkflowException) {
            $this->assertFalse($workflow->fresh()->is_active);
        }
    }

    public function test_other_widgets_can_connect_only_to_the_same_domain_and_zone(): void
    {
        DB::table('accounts')->insert(['user_id'=>1,'widget'=>'default','subdomain'=>'client','zone'=>'ru','active'=>true,'refresh_token'=>'test']);
        $account = (new Account)->forceFill(['user_id'=>1,'widget'=>'workflows','subdomain'=>'client','zone'=>'ru']);
        $account->save();
        $account->zone = 'com';
        $this->expectException(ValidationException::class);
        $account->save();
    }

    public function test_lead_widget_requests_only_button_starts(): void
    {
        $script = file_get_contents(public_path('amocrm/workflows/manual-buttons/script.js'));
        preg_match('/render: function \(\) \{(.*?)\n            \},/s', $script, $render);
        $this->assertStringContainsString('mount()', $render[1]);
        $this->assertStringContainsString('isLeadCardArea()', $render[1]);
        $this->assertStringContainsString("source: 'amo-button'", $script);
        $this->assertStringContainsString('setWidgetVisibility(false)', $script);
        $this->assertStringContainsString('icon-v2-arrow-down', $script);
        $this->assertStringContainsString('caption__text">Потоки</span>', $script);
        $this->assertStringContainsString('clever-workflow-bulk__title">Потоки</div>', $script);
        $this->assertStringContainsString('Выберите поток</option>', $script);
        $this->assertStringContainsString('height:44px!important', $script);
        $this->assertStringContainsString('font-weight:400!important', $script);
        $this->assertStringNotContainsString('Сценарии Clever', $script);
        $this->assertStringNotContainsString('Сценарий #', $script);
        $this->assertStringNotContainsString('Нет включённых потоков с запуском', $script);
    }
}

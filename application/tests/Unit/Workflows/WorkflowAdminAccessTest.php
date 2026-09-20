<?php

namespace Tests\Unit\Workflows;

use App\Filament\App\Pages\WorkflowAdmin;
use App\Filament\WorkflowBuilder\Resources\WorkflowCredentialResource;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns\UsesWorkflowOwnerContext;
use App\Models\User;
use App\Models\Workflows\Workflow;
use App\Models\Workflows\WorkflowCredential;
use App\Models\Workflows\WorkflowRun;
use App\Services\Workflows\WorkflowAdminAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowAdminAccessTest extends TestCase
{
    private User $root;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        WorkflowListDatabase::prepare();
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_root')->default(false);
            $table->boolean('active')->default(true);
        });
        (require database_path('migrations/2026_09_14_210000_create_workflow_credentials_table.php'))->up();

        DB::table('users')->where('id', 1)->update(['is_root' => true]);
        DB::table('accounts')->insert([
            ['user_id' => 1, 'widget' => 'workflows', 'active' => true, 'subdomain' => 'admin'],
            ['user_id' => 2, 'widget' => 'workflows', 'active' => true, 'subdomain' => 'client'],
        ]);
        $this->root = User::query()->findOrFail(1);
        $this->owner = User::query()->findOrFail(2);

        DB::table('workflows')->insert([
            $this->workflowRow(1, 'Админский сценарий'),
            $this->workflowRow(2, 'Сценарий клиента'),
        ]);
        DB::table('workflow_runs')->insert([
            ['user_id' => 1, 'workflow_id' => 1, 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => 2, 'workflow_id' => 2, 'status' => 'failed', 'created_at' => now(), 'updated_at' => now()],
        ]);
        WorkflowCredential::query()->create([
            'user_id' => 2, 'provider' => 'telegram', 'name' => 'Бот поддержки', 'secret' => '123456:private-token',
        ]);
    }

    public function test_regular_user_remains_limited_to_own_data(): void
    {
        $this->actingAs($this->owner);
        $this->assertSame(['Сценарий клиента'], Workflow::query()->pluck('name')->all());
        $this->assertSame([2], WorkflowRun::query()->pluck('workflow_id')->all());
        $this->assertFalse(WorkflowAdmin::canAccess());
        $this->assertFalse(WorkflowCredentialResource::canViewAny());
    }

    public function test_root_sees_global_data_and_summary(): void
    {
        $this->actingAs($this->root);
        $this->assertCount(2, WorkflowResource::getEloquentQuery()->get());
        $this->assertCount(2, WorkflowRun::query()->get());
        $this->assertTrue(WorkflowAdmin::canAccess());
        $this->assertTrue(WorkflowCredentialResource::canViewAny());

        $summary = app(WorkflowAdmin::class)->summary();
        $this->assertSame(2, $summary['accounts']);
        $this->assertSame(2, $summary['workflows']);
        $this->assertSame(2, $summary['runs']);
        $this->assertSame(1, $summary['failed']);
        $this->assertSame(1, $summary['credentials']);
    }

    public function test_root_switches_only_the_current_request_to_the_owner(): void
    {
        $this->actingAs($this->root);
        $owner = WorkflowAdminAccess::useWorkflowOwner(Workflow::query()->findOrFail(2));
        $this->assertSame(2, $owner->getKey());
        $this->assertSame(2, auth()->id());
        $this->assertSame(['Сценарий клиента'], Workflow::query()->pluck('name')->all());
    }

    public function test_editor_owner_context_is_restored_on_follow_up_requests(): void
    {
        $this->actingAs($this->root);
        $workflow = Workflow::query()->findOrFail(2);
        $page = new class($workflow)
        {
            use UsesWorkflowOwnerContext;

            public function __construct(private Workflow $workflow) {}

            public function getRecord(): Workflow
            {
                return $this->workflow;
            }

            public function initialize(): void
            {
                $this->initializeWorkflowOwnerContext();
            }

            public function restore(): void
            {
                $this->restoreWorkflowOwnerContext();
            }
        };

        $page->initialize();
        $this->assertTrue($page->workflowAdminAccess);
        $this->assertSame(2, auth()->id());
        $this->actingAs($this->root);
        $page->restore();
        $this->assertSame(2, auth()->id());
    }

    public function test_regular_user_cannot_switch_to_another_owner(): void
    {
        $this->actingAs($this->root);
        $workflow = Workflow::query()->findOrFail(1);
        $this->actingAs($this->owner);

        try {
            WorkflowAdminAccess::useWorkflowOwner($workflow);
            $this->fail('Обычный пользователь не должен менять контекст владельца.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    public function test_credential_secret_stays_hidden(): void
    {
        $this->actingAs($this->root);
        $credential = WorkflowCredential::query()->firstOrFail();
        $this->assertArrayNotHasKey('secret', $credential->toArray());
        $this->assertStringNotContainsString('private-token', $credential->toJson());
    }

    public function test_root_admin_overview_renders(): void
    {
        Livewire::actingAs($this->root)->test(WorkflowAdmin::class)
            ->assertOk()->assertSee('client')->assertSee('Сценарий клиента')->assertSee('Все подключения');
    }

    /** @return array<string, mixed> */
    private function workflowRow(int $userId, string $name): array
    {
        return [
            'user_id' => $userId,
            'name' => $name,
            'is_active' => false,
            'trigger_type' => 'manual',
            'definition' => json_encode(['trigger' => ['type' => 'manual'], 'actions' => []]),
            'max_retries' => 0,
            'failure_strategy' => 'stop',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}

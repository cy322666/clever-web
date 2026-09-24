<?php

namespace Tests\Feature\Finder;

use App\Models\Integrations\Finder\Action;
use App\Models\Integrations\Finder\Conversation;
use App\Models\Integrations\Finder\Setting;
use App\Models\Workflows\WorkflowRun;
use App\Services\amoCRM\Client;
use App\Services\Finder\ActionExecutor;
use App\Services\Finder\FinderAccess;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Leek\FilamentWorkflows\Jobs\ExecuteWorkflowJob;
use Tests\Support\FinderDatabase;
use Tests\TestCase;

class ActionExecutorTest extends TestCase
{
    private function action(string $kind): Action
    {
        $setting = FinderDatabase::prepare();
        $conversation = Conversation::create(['setting_id' => $setting->id, 'chat_id' => 'chat', 'pending_since' => now(), 'cycle' => 1]);

        return Action::create([
            'setting_id' => $setting->id, 'conversation_id' => $conversation->id, 'cycle' => 1, 'attempt' => 2,
            'event' => 'overdue', 'kind' => $kind,
            'payload' => ['account_id' => 1, 'talk_id' => 117, 'lead_id' => null, 'contact_id' => 42, 'chat_id' => 'chat',
                'responsible_user_id' => null, 'task_type_id' => 1, 'task_text' => 'Ответить клиенту', 'task_due_minutes' => 15,
                'workflow_id' => 7, 'pending_since' => now()->toIso8601String(), 'replied_at' => null],
        ]);
    }

    private function executor(Client $client): ActionExecutor
    {
        return new class(app(FinderAccess::class), $client) extends ActionExecutor
        {
            public function __construct(FinderAccess $access, private Client $testClient)
            {
                parent::__construct($access);
            }

            protected function client(Setting $setting): Client
            {
                return $this->testClient;
            }
        };
    }

    public function test_task_is_created_on_current_talk_lead_for_current_responsible_once(): void
    {
        $action = $this->action('task');
        $client = $this->createMock(Client::class);
        $client->expects($this->exactly(3))->method('requestV4')->willReturnCallback(function ($method, $path, $payload = []) {
            if ($path === '/api/v4/talks/117') {
                return ['entity_id' => 55, 'entity_type' => 'lead', 'contact_id' => 42];
            }
            if ($path === '/api/v4/leads/55') {
                return ['id' => 55, 'responsible_user_id' => 99];
            }
            $this->assertSame('POST', $method);
            $this->assertSame('/api/v4/tasks', $path);
            $this->assertSame(55, $payload[0]['entity_id']);
            $this->assertSame(99, $payload[0]['responsible_user_id']);
            $this->assertSame('leads', $payload[0]['entity_type']);
            $this->assertSame('Ответить клиенту', $payload[0]['text']);

            return ['_embedded' => ['tasks' => [['id' => 987]]]];
        });
        $executor = $this->executor($client);
        $executor->deliver($action->id);
        $executor->deliver($action->id);
        $this->assertSame('succeeded', $action->refresh()->status);
        $this->assertSame(987, $action->result_id);
    }

    public function test_workflow_receives_counter_entity_and_account_and_is_queued_once(): void
    {
        $action = $this->action('workflow');
        Bus::fake();
        DB::table('workflows')->insert(['id' => 7, 'user_id' => 1, 'name' => 'Finder test', 'is_active' => true, 'trigger_type' => 'manual', 'definition' => '{}']);
        $client = $this->createMock(Client::class);
        $client->method('requestV4')->willReturnCallback(fn ($method, $path) => str_contains($path, 'talks') ? ['entity_id' => 55, 'entity_type' => 'lead'] : ['id' => 55]);
        $executor = $this->executor($client);
        $executor->deliver($action->id);
        $executor->deliver($action->id);
        $this->assertSame('succeeded', $action->refresh()->status, $action->error ?? '');
        $run = WorkflowRun::sole();
        $trigger = $run->context_data['trigger_data'];
        $this->assertSame(2, $trigger['finder']['attempt']);
        $this->assertSame(55, $trigger['lead']['id']);
        $this->assertSame(1, $trigger['account']['user_id']);
        Bus::assertDispatchedTimes(ExecuteWorkflowJob::class, 1);
    }

    public function test_current_responsible_uses_contact_when_talk_has_no_lead(): void
    {
        $action = $this->action('task');
        $action->update(['payload' => [...$action->payload, 'responsible_user_id' => 0, 'lead_id' => 55]]);
        $client = $this->createMock(Client::class);
        $client->expects($this->exactly(3))->method('requestV4')->willReturnCallback(function ($method, $path, $payload = []) {
            if ($path === '/api/v4/talks/117') {
                return ['entity_type' => 'contact', 'entity_id' => 42, 'contact_id' => 42];
            }
            if ($path === '/api/v4/contacts/42') {
                return ['id' => 42, 'responsible_user_id' => 123];
            }
            $this->assertSame('POST', $method);
            $this->assertSame('/api/v4/tasks', $path);
            $this->assertSame('contacts', $payload[0]['entity_type']);
            $this->assertSame(42, $payload[0]['entity_id']);
            $this->assertSame(123, $payload[0]['responsible_user_id']);
            $this->assertSame('Ответить клиенту', $payload[0]['text']);

            return ['_embedded' => ['tasks' => [['id' => 988]]]];
        });
        $this->executor($client)->deliver($action->id);
        $this->assertSame('succeeded', $action->refresh()->status);
    }

    public function test_selected_staff_overrides_current_responsible_and_task_text_is_preserved(): void
    {
        $action = $this->action('task');
        $text = "Уточнить детали: заказ № 7\nОтветить клиенту.";
        $action->update(['payload' => [...$action->payload, 'responsible_user_id' => 321, 'task_text' => $text]]);
        $client = $this->createMock(Client::class);
        $client->expects($this->exactly(3))->method('requestV4')->willReturnCallback(function ($method, $path, $payload = []) use ($text) {
            if ($path === '/api/v4/talks/117') {
                return ['entity_type' => 'lead', 'entity_id' => 55, 'contact_id' => 42];
            }
            if ($path === '/api/v4/leads/55') {
                return ['id' => 55, 'responsible_user_id' => 99];
            }
            $this->assertSame('POST', $method);
            $this->assertSame('/api/v4/tasks', $path);
            $this->assertSame(321, $payload[0]['responsible_user_id']);
            $this->assertSame($text, $payload[0]['text']);

            return ['_embedded' => ['tasks' => [['id' => 989]]]];
        });
        $this->executor($client)->deliver($action->id);
        $this->assertSame('succeeded', $action->refresh()->status);
    }

    public function test_foreign_workflow_is_not_executed_even_for_tampered_saved_payload(): void
    {
        $action = $this->action('workflow');
        Bus::fake();
        DB::table('workflows')->insert(['id' => 7, 'user_id' => 2, 'name' => 'Other', 'is_active' => true, 'trigger_type' => 'manual']);
        $client = $this->createMock(Client::class);
        $client->method('requestV4')->willReturn(['entity_id' => 55, 'entity_type' => 'lead', 'id' => 55]);
        $this->executor($client)->deliver($action->id);
        $this->assertSame('failed', $action->refresh()->status);
        Bus::assertNotDispatched(ExecuteWorkflowJob::class);
    }
}

<?php

namespace Tests\Unit\Workflows;

use App\Jobs\Workflows\SynchronizeAmoCrmWebhooks;
use App\Models\Core\Account;
use App\Models\Workflows\Workflow;
use App\Services\Workflows\WorkflowAmoCrmLoopGuard;
use App\Services\Workflows\WorkflowAmoCrmWebhookPayloadNormalizer;
use App\Services\Workflows\WorkflowAmoCrmWebhookService;
use App\Services\Workflows\WorkflowDebugInput;
use App\Workflows\Triggers\AmoCrmWebhookTriggerCatalog;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Leek\FilamentWorkflows\Engine\WorkflowExecutor;
use Leek\FilamentWorkflows\Jobs\ExecuteWorkflowJob;
use Leek\FilamentWorkflows\Models\WorkflowRun;
use Leek\FilamentWorkflows\Triggers\TriggerRegistry;
use Livewire\Livewire;
use Tests\Support\WorkflowCanvasFixture;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowAmoWebhookEventsTest extends TestCase
{
    // Official v4 subscription events plus the documented unsorted notifications in the CRM picker.
    private const EVENTS = 'responsible_lead responsible_contact responsible_company responsible_customer responsible_task restore_lead restore_contact restore_company add_lead add_contact add_company add_customer add_talk add_task update_lead update_contact update_company update_customer update_talk update_task delete_lead delete_contact delete_company delete_customer delete_task status_lead note_lead note_contact note_company note_customer add_message add_outgoing_message add_chat_template_review add_unsorted update_unsorted delete_unsorted';

    protected function setUp(): void
    {
        parent::setUp();
        WorkflowListDatabase::prepare();
        Http::preventStrayRequests();
        Bus::fake();
    }

    public function test_every_documented_event_is_registered_receivable_and_available_in_the_picker(): void
    {
        $expected = explode(' ', self::EVENTS);
        $actual = AmoCrmWebhookTriggerCatalog::eventCodes();
        sort($expected); sort($actual);
        $this->assertSame($expected, $actual);
        $html = Livewire::test(WorkflowCanvasFixture::class)->html();
        foreach (AmoCrmWebhookTriggerCatalog::classes() as $class) {
            $config = $class::defaultConfig();
            $payload = $this->payload($config['event']);
            $event = (new WorkflowAmoCrmWebhookPayloadNormalizer)->normalize($payload)['events'][$config['event']];
            $this->assertSame($config['entity'], $event['entity']);
            $this->assertSame($config['action'], $event['action']);
            $this->assertTrue(app(TriggerRegistry::class)->has($class::type()));
            $this->assertStringContainsString($class::type(), $html);
            $trigger = new $class;
            $this->assertTrue($trigger->validateConfig($config)['valid']);
            $this->assertTrue($trigger->shouldTrigger($config, $payload));
            $this->assertFalse($trigger->shouldTrigger($config, $payload, ['event'=>'not_this_event']));
            $this->assertSame($event['item'], $trigger->getContextData($config, $payload)['item']);
        }
        $this->assertStringContainsString('Входящее сообщение', $html);
        $this->assertStringContainsString('Исходящее сообщение', $html);
        $this->assertStringContainsString('Неразобранное', $html);
        Http::assertNothingSent();
    }

    public function test_new_events_can_be_selected_saved_and_scheduled_for_subscription_on_activation(): void
    {
        $this->app->instance('env', 'local');
        (new Account)->forceFill(['user_id'=>1, 'widget'=>'workflows', 'active'=>true, 'subdomain'=>'test', 'refresh_token'=>'test-only'])->saveQuietly();
        foreach (['add_message', 'add_outgoing_message', 'add_unsorted', 'update_unsorted', 'delete_unsorted'] as $event) {
            $type = 'amocrm-'.str_replace('_', '-', $event);
            $page = Livewire::test(WorkflowCanvasFixture::class)->call('beginTriggerAdd')->call('selectTriggerType', $type)
                ->call('callMountedAction')->assertHasNoErrors();
            $this->assertSame($event, $page->get('definition')['additional_triggers'][0]['config']['event']);
            $workflow = $this->workflow($event, active:false);
            $workflow->update(['is_active'=>true]);
            $this->assertTrue($workflow->fresh()->is_active);
        }
        Bus::assertDispatched(SynchronizeAmoCrmWebhooks::class);
        $this->assertEqualsCanonicalizing(['add_message', 'add_outgoing_message', 'add_unsorted', 'update_unsorted', 'delete_unsorted'], app(WorkflowAmoCrmWebhookService::class)->requiredEventsForUser(1));
        Http::assertNothingSent();
    }

    public function test_every_event_starts_its_matching_workflow_with_the_full_input_only(): void
    {
        $events = explode(' ', self::EVENTS);
        foreach ($events as $event) $this->workflow($event);
        $this->workflow('add_message', owner:2);
        $this->workflow('add_message', active:false);
        $contexts = [];
        $service = $this->service(count($events), $contexts);
        $account = (new Account)->forceFill(['id'=>1, 'user_id'=>1, 'subdomain'=>'test']);
        foreach ($events as $event) {
            $payload = $this->payload($event);
            $result = $service->handleIncomingWebhook($account, $payload);
            $this->assertSame(1, $result['started'], $event);
            $context = end($contexts);
            $this->assertSame($event, $context['event']);
            $this->assertSame('trigger', $context['_workflow_start_node_id']);
            $this->assertSame($payload, $context['payload']);
            $this->assertSame($context['item'], $context[$context['entity']]);
        }
        Bus::assertDispatchedTimes(ExecuteWorkflowJob::class, count($events));
        Http::assertNothingSent();
    }

    public function test_message_batches_keep_each_uuid_text_attachment_and_direction(): void
    {
        $this->workflow('add_message'); $this->workflow('add_outgoing_message');
        $incoming = ['id'=>'d909ca8b-0558-4d47-9977-a30279af533a', 'text'=>'Первое', 'chat_id'=>'chat-uuid', 'contact_id'=>'42', 'origin'=>'telegram', 'author'=>['type'=>'external'], 'attachment'=>['type'=>'picture', 'link'=>'https://example.test/photo.jpg']];
        $second = array_replace($incoming, ['id'=>'c52d982f-0558-4d47-9977-a30279af533b', 'text'=>'Второе']);
        $outgoing = array_replace($incoming, ['id'=>'f709ca8b-0558-4d47-9977-a30279af533c', 'text'=>'Ответ', 'type'=>'outgoing', 'author'=>['type'=>'internal', 'user_id'=>'7']]);
        $contexts = [];
        $result = $this->service(3, $contexts)->handleIncomingWebhook((new Account)->forceFill(['id'=>1, 'user_id'=>1]), ['message'=>['add'=>[$incoming, $second]], 'outgoing_message'=>['add'=>[$outgoing]]]);
        $this->assertSame(3, $result['started']);
        $this->assertSame([$incoming, $second, $outgoing], array_column($contexts, 'item'));
        $this->assertSame(['add_message','add_message','add_outgoing_message'], array_column($contexts, 'event'));
        Bus::assertDispatchedTimes(ExecuteWorkflowJob::class, 3);
    }

    public function test_debug_input_supports_message_uuids_and_unsorted_uids(): void
    {
        foreach (['message', 'outgoing_message'] as $entity) {
            $rows = WorkflowDebugInput::defaults($entity, ['id'=>'message-uuid', 'text'=>'Привет']);
            $this->assertSame('text', $rows[0]['type']);
            $input = WorkflowDebugInput::build($entity, 'add_'.$entity, $rows);
            $this->assertSame('message-uuid', $input['item']['id']);
            $this->assertSame('Привет', $input[$entity]['text']);
        }
        $input = WorkflowDebugInput::build('unsorted', 'delete_unsorted', [['key'=>'uid', 'type'=>'text', 'value'=>'opaque-uid'], ['key'=>'action', 'type'=>'text', 'value'=>'accept']]);
        $this->assertSame(['uid'=>'opaque-uid','action'=>'accept'], $input['item']);
    }

    public function test_subscription_request_contains_the_enabled_events_including_messages_and_unsorted(): void
    {
        $events = explode(' ', self::EVENTS);
        foreach ($events as $event) $this->workflow($event);
        config(['workflow-webhooks.public_url'=>'https://app.example.test']);
        $account = (new Account)->forceFill(['id'=>1,'user_id'=>1,'active'=>true,'widget'=>'workflows','subdomain'=>'webhook-test','access_token'=>'test-only','refresh_token'=>'test-only', 'client_id'=>'webhook-events-test', 'client_secret'=>'test-only', 'redirect_uri'=>'https://app.example.test/oauth', 'zone'=>'ru']);
        $service = app(WorkflowAmoCrmWebhookService::class);
        $url = $service->callbackUrl($account);
        $installed = [];
        Http::fake(function ($request) use (&$installed, $url) {
            $this->assertSame('https://webhook-test.amocrm.ru/api/v4/webhooks', $request->url());
            if ($request->method() === 'POST') {
                $this->assertSame($url, $request['destination']);
                $installed = $request['settings'];
                return Http::response(['destination'=>$url,'settings'=>$installed], 201);
            }
            $this->assertSame('GET', $request->method());
            return Http::response(['_embedded'=>['webhooks'=>$installed === [] ? [] : [['destination'=>$url,'settings'=>$installed,'disabled'=>false]]]]);
        });
        $result = $service->synchronizeAccount($account);
        $this->assertTrue($result['ok'], $result['message'] ?? '');
        $this->assertTrue($result['matches']);
        $this->assertEqualsCanonicalizing($events, $installed);
        Http::assertSentCount(3);
    }

    private function workflow(string $event, int $owner = 1, bool $active = true): Workflow
    {
        [$action, $entity] = explode('_', $event, 2);
        $workflow = (new Workflow)->forceFill(['user_id'=>$owner, 'name'=>$event, 'is_active'=>$active, 'trigger_type'=>'webhook', 'definition'=>[
            'trigger'=>['type'=>'amocrm-'.str_replace('_','-',$event), 'config'=>['source'=>'amocrm','event'=>$event,'entity'=>$entity,'action'=>$action]],
            'actions'=>[['id'=>'delay','type'=>'workflow_delay','config'=>['seconds'=>1]]],
        ]]);
        $workflow->saveQuietly();
        return $workflow;
    }

    private function payload(string $event): array
    {
        [$action, $entity] = explode('_', $event, 2);
        $key = ['lead'=>'leads', 'contact'=>'contacts', 'company'=>'contacts', 'customer'=>'customers', 'task'=>'tasks', 'talk'=>'talks', 'chat_template_review'=>'chat_template_reviews'][$entity] ?? $entity;
        $item = $entity === 'unsorted' ? ['uid'=>'opaque-uid', 'action'=>'accept'] : ['id'=>'123', 'name'=>'Тест', 'custom_data'=>['keep'=>false]];
        if (in_array($entity, ['contact','company'], true)) $item['type'] = $entity;
        return [$key=>[$action=>[$item]]];
    }

    private function service(int $count, array &$contexts): WorkflowAmoCrmWebhookService
    {
        $runs = [];
        for ($index=0; $index<$count; $index++) {
            $run = $this->getMockBuilder(WorkflowRun::class)->onlyMethods(['update'])->getMock();
            $run->forceFill(['id'=>$index+1]);
            $run->expects($this->once())->method('update')->willReturnCallback(function ($data) use (&$contexts) {
                $contexts[] = $data['context_data']['trigger_data'];
                return true;
            });
            $runs[] = $run;
        }
        $executor = $this->createMock(WorkflowExecutor::class);
        $executor->expects($this->exactly($count))->method('start')->willReturnOnConsecutiveCalls(...$runs);
        $guard = $this->createMock(WorkflowAmoCrmLoopGuard::class);
        $guard->method('matchingRecentMutation')->willReturn(null);
        return new WorkflowAmoCrmWebhookService(new WorkflowAmoCrmWebhookPayloadNormalizer, $executor, $guard);
    }
}

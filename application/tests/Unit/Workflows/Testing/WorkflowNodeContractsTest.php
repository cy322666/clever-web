<?php

declare(strict_types=1);

namespace Tests\Unit\Workflows\Testing;

use App\Models\Core\Account;
use App\Models\User;
use App\Services\amoCRM\Client;
use App\Services\Workflows\Testing\WorkflowNodeSamples;
use App\Services\Workflows\WorkflowAmoCrmActionExecutor;
use App\Services\Workflows\WorkflowAmoCrmLoopGuard;
use App\Services\Workflows\WorkflowAmoReadCatalog;
use App\Workflows\Actions\RunWorkflowAction;
use App\Workflows\Actions\MultiChannelNotificationAction;
use App\Workflows\Actions\TelegramSendMessageAction;
use App\Workflows\Actions\WorkflowAmoCrmActionCatalog;
use App\Workflows\Actions\WorkflowHttpRequestAction;
use App\Workflows\Context\WorkflowContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\TestCase;

class WorkflowNodeContractsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowCanvasDatabase::prepare();
        Http::preventStrayRequests();
        Sleep::fake();
    }

    public function test_every_registered_action_has_an_explicit_sample_and_unsupported_actions_are_not_passes(): void
    {
        $samples = WorkflowNodeSamples::samples();
        $types = array_keys(app(ActionRegistry::class)->all());
        sort($types);
        $this->assertSame($types, array_keys($samples), 'Add an explicit JSON example when registering a node.');
        foreach ($samples as $type => $sample) {
            $this->assertSame('synthetic_contract', $sample['provenance'], $type);
            $this->assertSame($type, $sample['type']);
            $this->assertNotEmpty($sample['label']);
            $unsupported = in_array($type, WorkflowAmoCrmActionCatalog::unsupportedWorkflowTypes(), true);
            $this->assertSame($unsupported ? 'unsupported' : 'supported', $sample['availability'], $type);
            if ($unsupported) {
                $this->assertSame('unsupported', $sample['execution_status']);
                $this->assertFalse($sample['expected_result']['success']);
            }
        }
        $this->assertJson(json_encode($samples, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        Http::assertNothingSent();
    }

    public static function amoCases(): array
    {
        return array_map(fn ($type) => [$type], [
            'amocrm_create_lead', 'amocrm_create_contact', 'amocrm_create_company', 'amocrm_copy_lead',
            'amocrm_update_lead_fields', 'amocrm_update_contact_fields', 'amocrm_update_company_fields',
            'amocrm_create_task', 'amocrm_add_note', 'amocrm_change_tags', 'amocrm_change_lead_status',
            'amocrm_link_entity', 'amocrm_unlink_entity', 'amocrm_start_salesbot', 'amocrm_query_leads',
            'amocrm_get_contact', 'amocrm_contact_leads', 'amocrm_read', 'amocrm_find_entity',
        ]);
    }

    #[DataProvider('amoCases')]
    public function test_amo_action_calls_the_expected_endpoint_and_returns_the_documented_json(string $type): void
    {
        $sample = WorkflowNodeSamples::samples()[$type];
        $lead = ['id' => 101, 'name' => 'QA lead', 'price' => 0, 'pipeline_id' => 10, 'status_id' => 143, 'responsible_user_id' => 1];
        $contact = ['id' => 201, 'name' => 'QA contact', '_embedded' => ['leads' => [['id' => 101]]]];
        Http::fake(function ($request) use ($type, $lead, $contact) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/run')) return Http::response([], 202);
            if ($request->method() === 'POST') {
                $collection = str_ends_with($path, '/notes') ? 'notes' : basename($path);
                $id = ['leads' => 101, 'contacts' => 201, 'companies' => 301, 'tasks' => 401, 'notes' => 501][$collection] ?? 1;
                return Http::response(['_embedded' => [$collection => [['id' => $id]]]], 201);
            }
            if ($request->method() === 'PATCH') return Http::response(['id' => (int) basename($path)]);
            return Http::response(match ($path) {
                '/api/v4/leads/101' => $lead,
                '/api/v4/contacts/201' => $contact,
                '/api/v4/leads' => ['_embedded' => ['leads' => [$lead]]],
                '/api/v4/contacts' => ['_embedded' => ['contacts' => []]],
                default => throw new \RuntimeException('Unexpected mocked request '.$path.' for '.$type),
            });
        });
        $account = (new Account)->forceFill(['id' => 1, 'user_id' => 1, 'endpoint' => 'https://workflow-amo-contract.example', 'access_token' => 'synthetic-contract-only']);
        $executor = new WorkflowAmoCrmActionExecutor($this->createMock(WorkflowAmoCrmLoopGuard::class));
        $context = (new WorkflowContext)->setTriggerData($sample['input']);
        $client = (new \ReflectionClass(Client::class))->newInstanceWithoutConstructor();
        [$handler, $entity] = array_pad(explode(':', $sample['handler']), 2, null);
        $args = match ($handler) {
            'createEntity' => [$client, $account, $entity, $sample['config'], $context],
            'queryLeads', 'contactLeads', 'readAmo' => [$account, $sample['config']],
            'getContact', 'startSalesbot' => [$account, $sample['config'], $context],
            default => [$client, $account, $sample['config'], $context],
        };
        $result = (new \ReflectionMethod($executor, $handler))->invokeArgs($executor, $args);
        $this->assertSame($sample['expected_result'], $result, $type);
        $recorded = Http::recorded();
        $this->assertCount(count($sample['expected_requests']), $recorded, $type);
        foreach ($sample['expected_requests'] as $index => $expected) {
            $sent = $recorded[$index][0];
            $this->assertSame($expected['method'], $sent->method());
            $this->assertSame($expected['path'], parse_url($sent->url(), PHP_URL_PATH));
            parse_str(parse_url($sent->url(), PHP_URL_QUERY) ?: '', $query);
            $this->assertEquals($expected['query'], $query, $type.' query');
            if ($expected['body'] !== null) $this->assertEquals($expected['body'], $sent->data(), $type.' body');
        }
    }

    public static function publicCases(): array
    {
        return array_map(fn ($type) => [$type], ['control-condition', 'workflow_javascript', 'workflow_delay', 'workflow_filter_list', 'http_request', 'telegram_send_message']);
    }

    #[DataProvider('publicCases')]
    public function test_public_handlers_return_documented_results_without_live_side_effects(string $type): void
    {
        $sample = WorkflowNodeSamples::samples()[$type];
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => WorkflowNodeSamples::samples()['telegram_send_message']['output']]),
            'workflow-contract.example/*' => Http::response(['accepted' => true]),
        ]);
        $config = $sample['config'];
        $action = app(ActionRegistry::class)->resolve($type);
        if ($type === 'http_request') $action = new class extends WorkflowHttpRequestAction {
            protected function addresses(string $host): array { return ['93.184.216.34']; }
        };
        if ($type === 'telegram_send_message') {
            unset($config['credential_id']);
            $config = TelegramSendMessageAction::protectConfig($config + ['bot_token' => '12345:synthetic-contract-token']);
        }
        $result = $action->handle($config, (new WorkflowContext)->setTriggerData($sample['input']));
        $this->assertSame($sample['expected_result'], $result, $type);
        if ($sample['expected_requests'] === []) Http::assertNothingSent();
        else {
            Http::assertSentCount(1);
            $sent = Http::recorded()[0][0];
            $this->assertSame($sample['expected_requests'][0]['body'], $sent->data());
        }
    }

    public function test_every_read_operation_builds_a_local_get_path_and_all_hidden_operations_remain_explicit(): void
    {
        $operations = WorkflowNodeSamples::readOperations();
        $this->assertSame(array_keys(WorkflowAmoReadCatalog::operations()), array_keys($operations));
        foreach ($operations as $key => $operation) {
            $request = WorkflowAmoReadCatalog::build($operation['config']);
            $this->assertStringStartsWith('/api/v4/', $request['path'], $key);
            $this->assertStringNotContainsString('{', $request['path'], $key);
            $this->assertSame([], $request['query']);
            $this->assertNull($operation['output']);
            $this->assertSame('path_contract_only', $operation['execution_status']);
        }
        Http::assertNothingSent();
    }

    public function test_unsupported_examples_and_recursive_call_are_real_failure_contracts(): void
    {
        $executor = new WorkflowAmoCrmActionExecutor($this->createMock(WorkflowAmoCrmLoopGuard::class));
        foreach (WorkflowNodeSamples::samples() as $type => $sample) {
            if ($sample['availability'] !== 'unsupported') continue;
            $result = $type === 'run_workflow'
                ? (new RunWorkflowAction)->handle($sample['config'], (new WorkflowContext)->setWorkflowId(15))
                : (new \ReflectionMethod($executor, 'unsupported'))->invoke($executor, $type);
            $this->assertSame($sample['expected_result'], $result, $type);
        }
        Http::assertNothingSent();
    }

    public function test_telegram_forbidden_is_explained_and_is_not_retried(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Forbidden: bot was blocked by the user'], 403)]);
        $config = TelegramSendMessageAction::protectConfig(['bot_token' => '12345:synthetic-contract-token', 'chat_id' => '-100123', 'text' => 'QA message']);
        $result = (new TelegramSendMessageAction)->handle($config, new WorkflowContext);
        $this->assertSame(WorkflowNodeSamples::samples()['telegram_send_message']['error_examples'][0], $result);
        $this->assertStringNotContainsString('synthetic-contract-token', json_encode($result));
        Http::assertSentCount(1);
    }

    public function test_notification_result_is_generated_with_fake_delivery_channels(): void
    {
        Notification::fake();
        Mail::shouldReceive('raw')->once()->with('QA message', \Mockery::type('callable'))->andReturnNull();
        config(['services.telegram.token' => '', 'services.telegram.chat_id' => '']);
        $user = (new User)->forceFill(['id' => 1, 'name' => 'QA', 'email' => 'qa@example.invalid']);
        $action = new class($user) extends MultiChannelNotificationAction {
            public function __construct(private User $recipient) {}
            protected function resolveRecipients(array $config, ?\Leek\FilamentWorkflows\Context\WorkflowContext $context): array { return [$this->recipient]; }
        };
        $sample = WorkflowNodeSamples::samples()['send_notification'];
        $this->assertSame($sample['expected_result'], $action->handle($sample['config'], new WorkflowContext));
        Notification::assertSentTo($user, \Filament\Notifications\DatabaseNotification::class);
        Http::assertNothingSent();
    }

    public function test_distribution_missing_queue_fails_before_mutating_the_lead(): void
    {
        $executor = new WorkflowAmoCrmActionExecutor($this->createMock(WorkflowAmoCrmLoopGuard::class));
        $client = (new \ReflectionClass(Client::class))->newInstanceWithoutConstructor();
        $sample = WorkflowNodeSamples::samples()['amocrm_distribution_queue'];
        $config = $sample['config'];
        unset($config['distribution_queue_uuid']);
        $result = (new \ReflectionMethod($executor, 'distributeLead'))->invoke($executor, $client, new Account, $config, null);
        $this->assertSame($sample['error_examples'][0], $result);
        Http::assertNothingSent();
    }

    public function test_amo_403_is_a_failure_and_no_mutation_is_retried(): void
    {
        Http::fake(['workflow-amo-contract.example/*' => Http::response(['title' => 'Forbidden', 'detail' => 'Access denied'], 403)]);
        $executor = new WorkflowAmoCrmActionExecutor($this->createMock(WorkflowAmoCrmLoopGuard::class));
        $client = (new \ReflectionClass(Client::class))->newInstanceWithoutConstructor();
        $sample = WorkflowNodeSamples::samples()['amocrm_update_lead_fields'];
        $account = (new Account)->forceFill(['endpoint' => 'https://workflow-amo-contract.example', 'access_token' => 'synthetic-contract-only']);
        try {
            (new \ReflectionMethod($executor, 'updateFields'))->invoke($executor, $client, $account, $sample['config'], null);
            $this->fail('An amoCRM denial must not produce a success result.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('403', $error->getMessage());
            $this->assertStringNotContainsString('synthetic-contract-only', $error->getMessage());
        }
        Http::assertSentCount(1);
    }
}

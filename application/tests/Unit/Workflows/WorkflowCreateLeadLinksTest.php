<?php

namespace Tests\Unit\Workflows;

use App\Models\Core\Account;
use App\Services\Workflows\WorkflowAmoCrmActionExecutor;
use App\Services\Workflows\WorkflowAmoCrmLoopGuard;
use App\Workflows\Context\WorkflowContext;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\Support\WorkflowCanvasFixture;
use Tests\TestCase;

class WorkflowCreateLeadLinksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowCanvasDatabase::prepare();
        Http::preventStrayRequests();
    }

    public function test_form_saves_optional_ids_and_variable_expressions(): void
    {
        $page = Livewire::test(WorkflowCanvasFixture::class, ['workflowActions' => [
            ['id' => 'lead', 'type' => 'amocrm_create_lead', 'config' => ['name' => 'Новая сделка']],
        ]])->call('openWorkflowActionEditor', 'lead')
            ->assertSet('mountedActions.0.name', 'configureWorkflowAction')
            ->set('mountedActions.0.data.contact_id', '{{contact.id}}')
            ->set('mountedActions.0.data.company_id', '88')
            ->call('callMountedAction')->assertHasNoErrors();
        $this->assertSame('{{contact.id}}', $page->get('workflowActions')[0]['config']['contact_id']);
        $this->assertSame('88', $page->get('workflowActions')[0]['config']['company_id']);
        Http::assertNothingSent();
    }

    public function test_lead_and_explicit_links_are_sent_in_one_post_without_borrowing_context_contact(): void
    {
        Http::fake(['https://lead-links.test/api/v4/leads' => Http::response(['_embedded' => ['leads' => [['id' => 501]]]], 201)]);
        $context = (new WorkflowContext)->setTriggerData(['entity' => 'contact', 'contact' => ['id' => 999]]);
        $result = $this->create(['name' => 'Новая', 'contact_id' => '77', 'company_id' => 88, 'tags' => 'VIP'], $context);
        $this->assertTrue($result['success']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request[0]['_embedded']['contacts'] === [['id' => 77]]
            && $request[0]['_embedded']['companies'] === [['id' => 88]]
            && $request[0]['_embedded']['tags'] === [['name' => 'VIP']]);
    }

    public function test_empty_optional_fields_keep_existing_create_payload(): void
    {
        Http::fake(['https://lead-links.test/api/v4/leads' => Http::response(['_embedded' => ['leads' => [['id' => 501]]]], 201)]);
        $this->create(['name' => 'Новая', 'contact_id' => '', 'company_id' => null]);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->data() === [['name' => 'Новая']]);
    }

    public function test_lead_form_and_activation_validation_allow_an_empty_name(): void
    {
        Livewire::test(WorkflowCanvasFixture::class, ['workflowActions' => [
            ['id'=>'lead', 'type'=>'amocrm_create_lead', 'config'=>[]],
        ]])->call('openWorkflowActionEditor', 'lead')
            ->set('mountedActions.0.data.name', '')
            ->call('callMountedAction')->assertHasNoErrors();
        $this->assertSame([], \App\Services\Workflows\WorkflowDefinitionValidator::configIssues('amocrm_create_lead', []));
        $this->assertNotEmpty(\App\Services\Workflows\WorkflowDefinitionValidator::configIssues('amocrm_create_company', []));
        Http::assertNothingSent();
    }

    public function test_empty_lead_name_is_omitted_instead_of_overriding_amocrm_default(): void
    {
        Http::fake(['https://lead-links.test/api/v4/leads' => Http::response(['_embedded' => ['leads' => [['id' => 501]]]], 201)]);
        foreach ([[], ['name'=>null], ['name'=>''], ['name'=>'   ']] as $config) $this->create($config);
        Http::assertSentCount(4);
        foreach (Http::recorded() as [$request]) $this->assertSame('[{}]', $request->body());
    }

    public function test_invalid_ids_are_rejected_before_creating_anything(): void
    {
        foreach (['contact_id','company_id'] as $field) {
            foreach ([0, -1, 'text', '{{missing.id}}', [], true, 1.5] as $value) {
                try {
                    $this->create(['name' => 'Новая', $field => $value]);
                    $this->fail('Invalid ID was accepted');
                } catch (\InvalidArgumentException $error) {
                    $this->assertStringContainsString('положительным целым', $error->getMessage());
                }
            }
        }
        Http::assertNothingSent();
    }

    private function create(array $config, ?WorkflowContext $context = null): array
    {
        $executor = new WorkflowAmoCrmActionExecutor($this->createMock(WorkflowAmoCrmLoopGuard::class));
        $method = new \ReflectionMethod($executor, 'createEntity');
        $client = (new \ReflectionClass($method->getParameters()[0]->getType()->getName()))->newInstanceWithoutConstructor();
        return $method->invoke($executor, $client,
            (new Account)->forceFill(['id' => 1, 'endpoint' => 'https://lead-links.test', 'access_token' => 'test-only']),
            'lead', $config, $context);
    }
}

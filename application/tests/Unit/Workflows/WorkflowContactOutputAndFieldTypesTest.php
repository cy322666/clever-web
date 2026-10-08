<?php

namespace Tests\Unit\Workflows;

use App\Models\amoCRM\Field;
use App\Models\Core\Account;
use App\Services\Workflows\WorkflowAmoCrmActionExecutor;
use App\Services\Workflows\WorkflowAmoCrmLoopGuard;
use App\Services\Workflows\WorkflowOutputView;
use App\Workflows\Context\WorkflowContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowContactOutputAndFieldTypesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowListDatabase::prepare();
        (require database_path('migrations/2023_09_01_120654_create_fields_table.php'))->up();
        Schema::table('amocrm_fields', fn (Blueprint $table) => $table->boolean('active')->default(true));
        Http::preventStrayRequests();
    }

    private function executor(): WorkflowAmoCrmActionExecutor
    {
        return new WorkflowAmoCrmActionExecutor($this->createMock(WorkflowAmoCrmLoopGuard::class));
    }

    private function field(int $id, string $type, int $user = 1, string $entity = 'contacts'): Field
    {
        return Field::create(['field_id' => $id, 'type' => $type, 'user_id' => $user, 'entity_type' => $entity,
            'active' => true, 'name' => 'Поле '.$id, 'code' => 'FIELD_'.$id]);
    }

    private function account(): Account
    {
        return (new Account)->forceFill(['id' => 1, 'user_id' => 1, 'endpoint' => 'https://field-types.test', 'access_token' => 'test-only']);
    }

    public function test_number_and_text_fields_use_string_transport_without_losing_precision_or_zero(): void
    {
        $prepare = new \ReflectionMethod(WorkflowAmoCrmActionExecutor::class, 'prepareCustomFieldValues');
        foreach (['numeric', 'text', 'textarea'] as $type) {
            $field = new Field(['type' => $type]);
            foreach ([17, 0, -5, 1.25, '17.00', '00017', '9223372036854775808123', '', null] as $value) {
                $actual = $prepare->invoke($this->executor(), [['value' => $value]], $field);
                $this->assertSame([['value' => is_int($value) || is_float($value) ? (string) $value : $value]], $actual);
            }
        }
        $this->assertSame([['value' => 'Привет']], $prepare->invoke($this->executor(), [['value' => 'Привет']], new Field(['type' => 'text'])));
    }

    public function test_other_field_types_and_enum_metadata_are_untouched(): void
    {
        $prepare = new \ReflectionMethod(WorkflowAmoCrmActionExecutor::class, 'prepareCustomFieldValues');
        foreach (['checkbox' => true, 'date' => 1791468000, 'select' => 17, 'multitext' => '+70000000000', 'legal_entity' => ['name' => 'Company']] as $type => $value) {
            $values = [['value' => $value, 'enum_id' => 123]];
            $this->assertSame($values, $prepare->invoke($this->executor(), $values, new Field(['type' => $type])));
        }
        $values = [['value' => 17, 'enum_code' => 'WORK']];
        $this->assertSame($values, $prepare->invoke($this->executor(), $values, null));
        $this->assertSame([['value' => '17', 'enum_code' => 'WORK']], $prepare->invoke($this->executor(), $values, new Field(['type' => 'text'])));
    }

    public function test_non_numeric_input_is_rejected_before_sending_any_request(): void
    {
        $this->field(1031703, 'numeric');
        $method = new \ReflectionMethod(WorkflowAmoCrmActionExecutor::class, 'customFieldsPayload');
        foreach (['abc', '1,5', true, INF, NAN] as $value) {
            try {
                $method->invoke($this->executor(), $this->account(), 'contact', [['field' => 1031703, 'value' => $value]]);
                $this->fail('Invalid numeric input was accepted');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('ожидается число', $exception->getMessage());
                $this->assertStringContainsString('Поле 1031703', $exception->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_field_lookup_respects_account_and_entity(): void
    {
        $this->field(1031703, 'text', 1, 'contacts');
        $this->field(1031703, 'checkbox', 2, 'contacts');
        $this->field(1031703, 'checkbox', 1, 'leads');
        $method = new \ReflectionMethod(WorkflowAmoCrmActionExecutor::class, 'customFieldsPayload');
        $actual = $method->invoke($this->executor(), $this->account(), 'contact', [['field' => 1031703, 'value' => 17]]);
        $this->assertSame([['field_id' => 1031703, 'values' => [['value' => '17']]]], $actual);
    }

    public function test_update_sends_prepared_value_in_fields_and_json_modes(): void
    {
        $this->field(1031703, 'numeric');
        $this->field(20, 'checkbox');
        Http::fake(['https://field-types.test/*' => Http::response(['id' => 48487851])]);
        $method = new \ReflectionMethod(WorkflowAmoCrmActionExecutor::class, 'updateFields');
        $client = (new \ReflectionClass($method->getParameters()[0]->getType()->getName()))->newInstanceWithoutConstructor();
        foreach ([['fields' => [['field' => 1031703, 'value' => 17], ['field' => 20, 'value' => true]]],
            ['body_mode' => 'json', 'json_body' => ['custom_fields_values' => [
                ['field_code' => 'FIELD_1031703', 'values' => [['value' => 17]]],
                ['field_id' => 20, 'values' => [['value' => true]]],
            ]]]] as $config) {
            $result = $method->invoke($this->executor(), $client, $this->account(), $config + [
                'entity_source' => 'manual', 'target_entity' => 'contact', 'target_entity_id' => 48487851,
            ], new WorkflowContext);
            $this->assertTrue($result['success']);
        }
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('PATCH', $request->method());
            $this->assertSame('17', $request['custom_fields_values'][0]['values'][0]['value']);
            $this->assertTrue($request['custom_fields_values'][1]['values'][0]['value']);
        }
        Http::assertSentCount(2);
    }

    public function test_created_contact_is_an_object_and_link_response_cannot_replace_it(): void
    {
        $output = ['action' => 'created', 'entity_type' => 'contact', 'entity_id' => 48487851, 'amo_exchange' => [
            ['response' => ['body' => ['_embedded' => ['contacts' => [['id' => 48487851, 'is_deleted' => false, 'request_id' => '0']]]]]],
            ['response' => ['body' => ['_embedded' => ['links' => [['to_entity_id' => 123]]]]]],
        ]];
        $original = $output;
        $this->assertSame(['id' => 48487851, 'is_deleted' => false], WorkflowOutputView::value($output));
        $this->assertSame($original, $output);
        $context = (new WorkflowContext)->setStepOutput('created', $output)->setVariable('_node_names', ['Создать контакт' => ['created']]);
        $this->assertSame(48487851, $context->resolve('{{ $("Создать контакт").id }}'));
        $this->assertSame(48487851, $context->resolve('{{ $("Создать контакт")[0].id }}'));
        $this->assertSame('0', $context->resolve('{{ $node["created"].json.amo_exchange[0].response.body._embedded.contacts[0].request_id }}'));
        $this->assertSame(48487851, WorkflowOutputView::value(array_diff_key($output, ['amo_exchange' => true]))['id']);
    }

    public function test_create_sends_a_string_and_projects_the_single_returned_contact(): void
    {
        $this->field(1031703, 'numeric');
        Http::fake(fn ($request) => $request->method() === 'POST'
            ? Http::response(['_embedded' => ['contacts' => [['id' => 77, 'request_id' => '0']]]], 201)
            : Http::response(['_embedded' => ['contacts' => []]]));
        $executor = $this->executor();
        (new \ReflectionProperty($executor, 'captureAmoExchange'))->setValue($executor, true);
        $create = new \ReflectionMethod($executor, 'createEntity');
        $client = (new \ReflectionClass($create->getParameters()[0]->getType()->getName()))->newInstanceWithoutConstructor();
        $result = $create->invoke($executor, $client, $this->account(), 'contact', [
            'name' => 'Test Contact', 'fields' => [['field' => 1031703, 'value' => 17]],
        ], null);
        $result = (new \ReflectionMethod($executor, 'withAmoExchange'))->invoke($executor, $result, null);
        $this->assertTrue($result['success']);
        $this->assertSame(['id' => 77], WorkflowOutputView::value($result['output']));
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request[0]['custom_fields_values'][0]['values'][0]['value'] === '17');
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST'));
    }

    public function test_deduplicated_contact_is_selected_by_id_and_real_read_collections_stay_lists(): void
    {
        $body = ['_embedded' => ['contacts' => [['id' => 1], ['id' => 2, 'name' => 'Correct']]]];
        $output = ['action' => 'found_existing', 'entity_type' => 'contact', 'entity_id' => 2, 'amo_exchange' => [['response' => ['body' => $body]]]];
        $this->assertSame(['id' => 2, 'name' => 'Correct'], WorkflowOutputView::value($output));
        $read = ['data' => $body, 'items' => $body['_embedded']['contacts'], 'has_more' => false];
        $this->assertSame($body['_embedded']['contacts'], WorkflowOutputView::value($read));
    }
}

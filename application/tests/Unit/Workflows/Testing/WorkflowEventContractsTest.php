<?php

declare(strict_types=1);

namespace Tests\Unit\Workflows\Testing;

use App\Services\Workflows\Testing\WorkflowEventSamples;
use App\Services\Workflows\WorkflowAmoCrmWebhookPayloadNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Leek\FilamentWorkflows\Triggers\TriggerRegistry;
use LogicException;
use Tests\TestCase;

class WorkflowEventContractsTest extends TestCase
{
    private string $previousTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.timezone' => 'UTC', 'cache.default' => 'array',
            'database.default' => 'event_contracts',
            'database.connections.event_contracts' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]);
        DB::purge('event_contracts');
        Http::preventStrayRequests();
        $this->previousTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $this->travelTo(\Illuminate\Support\Carbon::parse(WorkflowEventSamples::CLOCK));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        date_default_timezone_set($this->previousTimezone);
        parent::tearDown();
    }

    public function test_every_runtime_trigger_has_a_json_serializable_reviewed_contract(): void
    {
        $registry = app(TriggerRegistry::class);
        $samples = WorkflowEventSamples::samples();
        $this->assertNotEmpty($samples);
        $this->assertSame(array_keys($registry->all()), array_keys($samples));
        $this->assertSame($samples, json_decode(json_encode($samples, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
        foreach ($samples as $type => $sample) {
            $this->assertSame($type, $sample['type']);
            $this->assertSame($registry->get($type), $sample['trigger_class']);
            $this->assertSame('synthetic_contract', $sample['provenance']);
            $this->assertFalse($sample['captured_from_account']);
            $this->assertNotEmpty($sample['label']);
        }
        Http::assertNothingSent();
    }

    public function test_new_runtime_trigger_cannot_silently_escape_the_catalog(): void
    {
        app(TriggerRegistry::class)->registerAs('unreviewed-test-start', \App\Workflows\Triggers\ManualTrigger::class);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('unreviewed-test-start');
        WorkflowEventSamples::samples();
    }

    public function test_every_webhook_survives_real_form_encoding_with_expected_identity_and_context(): void
    {
        $normalizer = new WorkflowAmoCrmWebhookPayloadNormalizer;
        foreach ($this->webhooks() as $type => $sample) {
            parse_str($sample['input']['encoded'], $received);
            $this->assertSame($sample['input']['payload'], $received, $type.' wire round trip');
            $normalized = $normalizer->normalize($received);
            $event = $sample['assertions']['event'];
            $actual = $normalized['events'][$event] ?? null;
            $this->assertNotNull($actual, $type.' missing event');
            $this->assertSame([$event], array_keys($normalized['events']), $type.' unexpectedly matched another event');
            $this->assertSame($sample['output']['normalized_event'], $actual, $type.' envelope');
            $this->assertSame($sample['assertions']['entity'], $actual['entity'], $type.' entity');
            $this->assertSame($sample['assertions']['identity'], $actual['item'][$sample['assertions']['identity_field']], $type.' identity');
            $this->assertCount(1, $actual['items']);
            $this->assertSame($normalized, $normalizer->normalize($normalized['payload']), $type.' idempotence');

            $trigger = app(TriggerRegistry::class)->resolve($type);
            $this->assertTrue($trigger->validateConfig($sample['config'])['valid'], $type.' config');
            $this->assertTrue($trigger->shouldTrigger($sample['config'], $received), $type.' dispatch match');
            $this->assertFalse($trigger->shouldTrigger($sample['config'], $received, ['event' => 'wrong_event']), $type.' event isolation');
            $this->assertFalse($trigger->validateConfig(array_replace($sample['config'], ['event' => 'wrong_event']))['valid'], $type.' config isolation');
            // Associative key order is irrelevant; strict values/types are checked by the envelope assertion above.
            $this->assertEquals($sample['output']['trigger_context'], $trigger->getContextData($sample['config'], $received), $type.' trigger context');
        }
        Http::assertNothingSent();
    }

    public function test_each_webhook_batch_preserves_two_distinct_items_and_order(): void
    {
        $normalizer = new WorkflowAmoCrmWebhookPayloadNormalizer;
        foreach ($this->webhooks() as $type => $sample) {
            $expected = $sample['output']['normalized_event'];
            $identityField = $sample['assertions']['identity_field'];
            $first = $expected['item'];
            $second = array_replace($first, [$identityField => $identityField === 'uid' ? 'qa-unsorted-0002' : '900002']);
            $payload = [$expected['payload_key'] => [$expected['action_key'] => [$first, $second]]];
            parse_str(http_build_query($payload, '', '&', PHP_QUERY_RFC1738), $received);
            $events = $normalizer->normalize($received)['events'];
            $this->assertSame([$expected['event']], array_keys($events), $type.' batch routing');
            $this->assertSame([$first, $second], $events[$expected['event']]['items'], $type.' batch cardinality/order');
            $this->assertSame($first, $events[$expected['event']]['item'], $type.' first item alias');
        }
        Http::assertNothingSent();
    }

    public function test_note_identity_is_not_confused_with_its_parent_entity(): void
    {
        foreach (['lead', 'contact', 'company', 'customer'] as $entity) {
            $sample = WorkflowEventSamples::samples()['amocrm-note-'.$entity];
            $trigger = app(TriggerRegistry::class)->resolve($sample['type']);
            $context = $trigger->getContextData($sample['config'], $sample['input']['payload']);
            $this->assertSame('920001', $context['note']['id']);
            $this->assertSame('900001', $context[$entity]['id']);
            $this->assertSame('900001', $context['note']['element_id']);
        }
    }

    public function test_mixed_contacts_and_companies_do_not_cross_route_or_duplicate(): void
    {
        $payload = ['contacts' => ['update' => [
            ['id' => '1', 'type' => 'contact', 'name' => 'Контакт'],
            ['id' => '2', 'type' => 'company', 'name' => 'Компания'],
        ]], 'companies' => ['update' => [['id' => '2', 'name' => 'Компания']]]];
        $events = (new WorkflowAmoCrmWebhookPayloadNormalizer)->normalize($payload)['events'];
        $this->assertSame(['update_contact', 'update_company'], array_keys($events));
        $this->assertSame(['1'], array_column($events['update_contact']['items'], 'id'));
        $this->assertSame(['2'], array_column($events['update_company']['items'], 'id'));

        // Two changes to the same entity must not collapse merely because their IDs match.
        $payload['companies']['update'][] = ['id' => '2', 'name' => 'Новое название'];
        $changed = (new WorkflowAmoCrmWebhookPayloadNormalizer)->normalize($payload)['events'];
        $this->assertSame(['Компания', 'Новое название'], array_column($changed['update_company']['items'], 'name'));
    }

    public function test_unknown_malformed_or_empty_notifications_have_no_routable_events(): void
    {
        $normalizer = new WorkflowAmoCrmWebhookPayloadNormalizer;
        foreach ([
            [], ['account' => ['id' => '900000']], ['leads' => 'garbage'],
            ['leads' => ['add' => []]], ['leads' => ['future_action' => [['id' => '1']]]],
            ['leads' => ['delete' => '-1']], ['tasks' => ['delete' => 'abc']],
            ['message' => ['add' => ['text' => 'missing identity']]],
            ['contacts' => ['add' => ['not-an-array']]],
            ['add' => [['id' => '1', 'type' => 'unsupported']]],
            ['unsorted' => ['delete' => 'invalid space']],
        ] as $payload) {
            $this->assertSame([], $normalizer->normalize($payload)['events']);
        }
    }

    public function test_company_equivalence_preserves_value_types_nested_objects_and_sequence_order(): void
    {
        $original = ['id' => '2', 'type' => 'company', 'fields' => [
            ['id' => '3', 'values' => ['one', 'two']],
        ], 'settings' => ['enabled' => '0', 'name' => 'QA']];
        $reordered = ['settings' => ['name' => 'QA', 'enabled' => '0'], 'fields' => [
            ['values' => ['one', 'two'], 'id' => '3'],
        ], 'id' => '2'];
        $differentType = $reordered;
        $differentType['settings']['enabled'] = 0;
        $differentOrder = $reordered;
        $differentOrder['fields'][0]['values'] = ['two', 'one'];
        $payload = ['contacts' => ['update' => [$original]], 'companies' => ['update' => [$reordered, $differentType, $differentOrder]]];

        $normalizer = new WorkflowAmoCrmWebhookPayloadNormalizer;
        $result = $normalizer->normalize($payload);
        $items = $result['events']['update_company']['items'];
        $this->assertCount(3, $items);
        $this->assertSame($original, $items[0]);
        $this->assertSame(0, $items[1]['settings']['enabled']);
        $this->assertSame(['two', 'one'], $items[2]['fields'][0]['values']);
        $this->assertSame($result, $normalizer->normalize($result['payload']));
    }

    public function test_every_other_start_has_a_matching_context_and_gate_contract(): void
    {
        foreach (WorkflowEventSamples::samples() as $type => $sample) {
            if (isset($sample['output']['normalized_event'])) continue;
            $input = $sample['input'];
            $subject = $input['subject'];
            if (isset($input['subject_class'])) {
                $subject = (new $input['subject_class'])->forceFill($subject);
                $this->assertFalse($subject->exists);
            }
            $trigger = app(TriggerRegistry::class)->resolve($type);
            $this->assertTrue($trigger->validateConfig($sample['config'])['valid'], $type.' config');
            $this->assertSame($sample['assertions']['should_trigger'], $trigger->shouldTrigger($sample['config'], $subject, $input['context']), $type.' gate');
            $this->assertSame($sample['output']['trigger_context'], $trigger->getContextData($sample['config'], $subject, $input['context']), $type.' context');
        }
        Http::assertNothingSent();
    }

    public function test_manual_schedule_parent_workflow_and_date_gates_reject_wrong_context(): void
    {
        $registry = app(TriggerRegistry::class);
        $this->assertFalse($registry->resolve('manual')->shouldTrigger([], null, []));
        $this->assertFalse($registry->resolve('workflow-completed')->shouldTrigger([], null, []));
        $this->assertFalse($registry->resolve('schedule')->shouldTrigger(
            ['timezone' => 'UTC', 'rules' => [['frequency' => 'daily', 'time' => '09:00']]], null,
            ['check_time' => '2026-09-19T09:01:00+00:00'],
        ));
        $date = WorkflowEventSamples::samples()['date-condition'];
        $subject = (new $date['input']['subject_class'])->forceFill($date['input']['subject']);
        $this->assertFalse($registry->resolve('date-condition')->shouldTrigger($date['config'], $subject, ['check_date' => '2026-09-20']));
        $this->assertFalse($registry->resolve('generic-webhook')->shouldTrigger(['source' => 'wrong_source'], [], []));
        Http::assertNothingSent();
    }

    private function webhooks(): array
    {
        return array_filter(WorkflowEventSamples::samples(), fn (array $sample): bool => isset($sample['output']['normalized_event']));
    }
}

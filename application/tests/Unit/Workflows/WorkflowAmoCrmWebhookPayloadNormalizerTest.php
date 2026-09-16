<?php

namespace Tests\Unit\Workflows;

use App\Services\Workflows\WorkflowAmoCrmWebhookPayloadNormalizer;
use PHPUnit\Framework\TestCase;

class WorkflowAmoCrmWebhookPayloadNormalizerTest extends TestCase
{
    public function test_official_task_and_whatsapp_envelopes_are_canonical_and_idempotent(): void
    {
        $task = ['id'=>'123', 'text'=>'Задача', 'result'=>['id'=>'7','text'=>'Готово']];
        $template = ['id'=>'6955','type'=>'waba','reviews'=>[['status'=>'review']],'is_on_review'=>'1'];
        $normalizer = new WorkflowAmoCrmWebhookPayloadNormalizer;
        $result = $normalizer->normalize(['task'=>['update'=>[[$task]]], 'add'=>[$template]]);
        $this->assertSame($task, $result['events']['update_task']['item']);
        $this->assertSame($template, $result['events']['add_chat_template_review']['item']);
        $this->assertSame($result, $normalizer->normalize($result['payload']));
    }

    public function test_scalar_deletions_and_unsorted_acceptance_keep_identifiers(): void
    {
        $normalizer = new WorkflowAmoCrmWebhookPayloadNormalizer;
        $deleted = $normalizer->normalize(['leads'=>['delete'=>'42'], 'tasks'=>['delete'=>['43','44']]]);
        $this->assertSame(['id'=>'42'], $deleted['events']['delete_lead']['item']);
        $this->assertSame([['id'=>'43'],['id'=>'44']], $deleted['events']['delete_task']['items']);
        foreach (['accept', 'decline'] as $action) {
            $item = ['uid'=>'opaque-uid','action'=>$action,$action.'_result'=>['leads'=>['42']]];
            $result = $normalizer->normalize(['unsorted'=>['delete'=>[$item]]]);
            $this->assertSame($item, $result['events']['delete_unsorted']['item']);
        }
    }

    public function test_unknown_or_malformed_notifications_do_not_trigger_workflows(): void
    {
        $normalizer = new WorkflowAmoCrmWebhookPayloadNormalizer;
        foreach ([['message'=>['add'=>[]]], ['message'=>['add'=>'garbage']], ['leads'=>['delete'=>'-1']], ['leads'=>['unknown'=>[['id'=>'123']]]], ['add'=>[['id'=>'42','type'=>'not-waba']]], ['message'=>['add'=>['text'=>'missing record']]]] as $payload) {
            $this->assertSame([], $normalizer->normalize($payload)['events']);
        }
    }

    public function test_it_normalizes_lead_events_from_amocrm_payload(): void
    {
        $result = (new WorkflowAmoCrmWebhookPayloadNormalizer())->normalize([
            'leads' => [
                'add' => [
                    [
                        'id' => 100500,
                        'name' => 'Новая сделка',
                    ],
                ],
            ],
        ]);

        $this->assertArrayHasKey('add_lead', $result['events']);
        $this->assertSame('lead', $result['events']['add_lead']['entity']);
        $this->assertSame('add', $result['events']['add_lead']['action']);
        $this->assertSame(100500, $result['events']['add_lead']['item']['id']);
    }

    public function test_it_normalizes_company_events_from_amocrm_company_payload(): void
    {
        $result = (new WorkflowAmoCrmWebhookPayloadNormalizer())->normalize([
            'companies' => [
                'update' => [
                    [
                        'id' => 333,
                        'name' => 'Компания',
                    ],
                ],
            ],
        ]);

        $this->assertArrayHasKey('update_company', $result['events']);
        $this->assertSame('company', $result['events']['update_company']['entity']);
        $this->assertSame('update', $result['events']['update_company']['action']);
        $this->assertSame('company', $result['events']['update_company']['item']['type']);
        $this->assertSame(333, $result['events']['update_company']['item']['id']);
    }

    public function test_contact_payload_without_type_still_exposes_contact_and_company_events(): void
    {
        $result = (new WorkflowAmoCrmWebhookPayloadNormalizer())->normalize([
            'contacts' => [
                'update' => [
                    [
                        'id' => 777,
                        'name' => 'Контакт без type',
                    ],
                ],
            ],
        ]);

        $this->assertArrayHasKey('update_contact', $result['events']);
        $this->assertArrayHasKey('update_company', $result['events']);
        $this->assertSame(777, $result['events']['update_contact']['item']['id']);
    }
}

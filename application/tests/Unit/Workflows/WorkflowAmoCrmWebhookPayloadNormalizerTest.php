<?php

namespace Tests\Unit\Workflows;

use App\Services\Workflows\WorkflowAmoCrmWebhookPayloadNormalizer;
use PHPUnit\Framework\TestCase;

class WorkflowAmoCrmWebhookPayloadNormalizerTest extends TestCase
{
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

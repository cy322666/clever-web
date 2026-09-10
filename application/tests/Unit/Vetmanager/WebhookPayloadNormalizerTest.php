<?php

namespace Tests\Unit\Vetmanager;

use App\Services\Vetmanager\WebhookPayloadNormalizer;
use PHPUnit\Framework\TestCase;

class WebhookPayloadNormalizerTest extends TestCase
{
    public function test_normalizes_nested_form_payload_and_redacts_secret(): void
    {
        $event = (new WebhookPayloadNormalizer)->normalize([
            'name' => 'admissionInvoicesSumChanged',
            'data' => [
                'id' => 123,
                'client_id' => 45,
                'invoices_sum' => '1900.50',
            ],
            'params' => [
                'dop_param1' => 'secret-value',
            ],
        ]);

        $this->assertSame('admissionInvoicesSumChanged', $event['event_name']);
        $this->assertSame('123', $event['admission_id']);
        $this->assertSame('secret-value', $event['params']['dop_param1']);
        $this->assertSame('***', $event['event_payload']['params']['dop_param1']);
    }

    public function test_normalizes_flat_bracket_keys_and_redacts_flat_secret(): void
    {
        $event = (new WebhookPayloadNormalizer)->normalize([
            'name' => 'admissionAccepted',
            'data[id]' => '987',
            'data[status]' => 'accepted',
            'params[dop_param1]' => 'secret-value',
        ]);

        $this->assertSame('987', $event['admission_id']);
        $this->assertSame('accepted', $event['data']['status']);
        $this->assertSame('secret-value', $event['params']['dop_param1']);
        $this->assertSame('***', $event['event_payload']['params[dop_param1]']);
    }

    public function test_only_accepts_configured_admission_events(): void
    {
        $normalizer = new WebhookPayloadNormalizer;

        $this->assertTrue($normalizer->isSupported('admissionAccepted'));
        $this->assertTrue($normalizer->isSupported('admissionInvoicesSumChanged'));
        $this->assertFalse($normalizer->isSupported('invoiceAdded'));
    }
}

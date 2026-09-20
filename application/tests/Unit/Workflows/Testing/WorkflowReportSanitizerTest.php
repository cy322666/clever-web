<?php

namespace Tests\Unit\Workflows\Testing;

use App\Services\Workflows\Testing\WorkflowReportSanitizer;
use PHPUnit\Framework\TestCase;

final class WorkflowReportSanitizerTest extends TestCase
{
    public function test_nested_secrets_are_redacted_without_losing_business_data(): void
    {
        $data = [
            'event' => 'leads.add',
            'item' => ['id' => 123, 'name' => 'QA deal', 'status_id' => 143, 'email' => 'qa@example.test', 'phone' => '+79991234567'],
            'token_count' => 10,
            'token_type' => 'Bearer',
            'access_token_expires_at' => 1234567890,
            'credential_id' => 4,
            'payload' => ['clientSecret' => 'abcdef12345', 'access_token' => 'access12345', 'refreshToken' => 'refresh12345', 'bot_token_encrypted' => 'ciphertext12345'],
            'headers' => ['Authorization' => ['Bearer abcdef12345'], 'X-API-Key' => 'api-key12345', 'Cookie' => 'session=123456', 'Accept' => 'application/json'],
            'credentials' => ['unknown_provider_field' => 'private12345', 'nested' => ['value' => 'private45678']],
        ];

        $safe = WorkflowReportSanitizer::sanitize($data);

        $this->assertSame($data['item'], $safe['item']);
        $this->assertSame(10, $safe['token_count']);
        $this->assertSame('Bearer', $safe['token_type']);
        $this->assertSame(1234567890, $safe['access_token_expires_at']);
        $this->assertSame(4, $safe['credential_id']);
        $this->assertSame(array_fill_keys(array_keys($data['payload']), '[REDACTED]'), $safe['payload']);
        $this->assertSame(['[REDACTED]'], $safe['headers']['Authorization']);
        $this->assertSame('application/json', $safe['headers']['Accept']);
        $this->assertSame('[REDACTED]', $safe['credentials']['nested']['value']);
    }

    public function test_telegram_urls_signed_webhook_urls_and_query_credentials_are_hidden(): void
    {
        $data = [
            'telegram' => 'https://api.telegram.org/bot12345:abcdefghijklmnopqrstuvwxyz012345/sendMessage',
            'telegram_file' => 'https://api.telegram.org/file/bot12345:abcdefghijklmnopqrstuvwxyz012345/photos/a.jpg',
            'webhook' => 'https://app.example.test/api/workflows/webhook/15/abc123signature?_workflow_test=1',
            'amo_hook' => '/api/amocrm/workflows/hook/23/xyz456signature',
            'signed' => 'https://example.test/resource?entity_id=123&X-Amz-Signature=signedsecret&access%5Ftoken=encodedsecret',
            'oauth' => 'https://example.test/callback?code=oauthcode12345&state=oauthstate12345&key=providerkey12345',
            'fragment' => 'https://example.test/#access_token=fragmentsecret&entity_id=123',
            'basic_url' => 'https://user:password@example.test/api/v4/leads/123',
        ];

        $safe = WorkflowReportSanitizer::sanitize($data);

        $this->assertSame('https://api.telegram.org/bot[REDACTED]/sendMessage', $safe['telegram']);
        $this->assertSame('https://api.telegram.org/file/bot[REDACTED]/photos/a.jpg', $safe['telegram_file']);
        $this->assertSame('https://app.example.test/api/workflows/webhook/15/[REDACTED]?_workflow_test=1', $safe['webhook']);
        $this->assertSame('/api/amocrm/workflows/hook/23/[REDACTED]', $safe['amo_hook']);
        $this->assertStringContainsString('entity_id=123', $safe['signed']);
        $this->assertStringNotContainsString('signedsecret', $safe['signed']);
        $this->assertStringNotContainsString('encodedsecret', $safe['signed']);
        $this->assertStringNotContainsString('fragmentsecret', $safe['fragment']);
        $this->assertSame('https://example.test/callback?code=[REDACTED]&state=[REDACTED]&key=[REDACTED]', $safe['oauth']);
        $this->assertSame('https://[REDACTED]@example.test/api/v4/leads/123', $safe['basic_url']);
    }

    public function test_serialized_json_bodies_and_header_name_value_lists_cannot_bypass_redaction(): void
    {
        $data = [
            'body' => '{"id":123,"nested":{"refresh_token":"refreshtoken12345"},"empty":{}}',
            'headers' => [['name' => 'Authorization', 'value' => 'Bearer abcdef12345'], ['key' => 'X-Auth-Token', 'value' => 'header-secret12345'], ['name' => 'X-Request-ID', 'value' => 'request12345']],
            'object' => (object) ['privateKey' => 'privatekey12345', 'id' => 123],
        ];

        $safe = WorkflowReportSanitizer::sanitize($data);

        $this->assertSame('[REDACTED]', json_decode($safe['body'], true)['nested']['refresh_token']);
        $this->assertInstanceOf(\stdClass::class, json_decode($safe['body'])->empty);
        $this->assertSame('[REDACTED]', $safe['headers'][0]['value']);
        $this->assertSame('[REDACTED]', $safe['headers'][1]['value']);
        $this->assertSame('X-Auth-Token', $safe['headers'][1]['key']);
        $this->assertSame('request12345', $safe['headers'][2]['value']);
        $this->assertInstanceOf(\stdClass::class, $safe['object']);
        $this->assertSame('[REDACTED]', $safe['object']->privateKey);
        $this->assertSame(123, $safe['object']->id);
    }

    public function test_exceptions_and_raw_headers_cannot_echo_known_credentials(): void
    {
        $data = [
            'error' => 'Remote service echoed access12345 and p%40ss%2Bword12345',
            'raw_headers' => "Authorization: Bearer opaque12345\r\nCookie: session=private12345\r\nAccept: application/json",
            'jwt_error' => 'Rejected eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjMifQ.signature12345',
            'password' => 'p@ss+word12345',
            'access_token' => 'access12345',
            '_redaction_secrets' => ['opaque-context12345'],
            'remote_error' => 'Rejected opaque-context12345',
        ];

        $safe = WorkflowReportSanitizer::sanitize($data);

        $this->assertSame('Remote service echoed [REDACTED] and [REDACTED]', $safe['error']);
        $this->assertSame('Rejected [REDACTED]', $safe['remote_error']);
        $this->assertSame(['[REDACTED]'], $safe['_redaction_secrets']);
        $json = json_encode($safe);
        foreach (['opaque12345', 'private12345', 'eyJhbGci', 'access12345', 'p%40ss'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertStringContainsString('Accept: application/json', $safe['raw_headers']);
    }

    public function test_json_scalar_types_and_empty_containers_remain_intact_and_sanitizer_is_idempotent(): void
    {
        $data = ['items' => [], 'empty' => (object) [], 'success' => false, 'count' => 0, 'null' => null, 'secret' => null, 'password' => 'short', 'normal' => '0'];
        $safe = WorkflowReportSanitizer::sanitize($data);
        $expected = $data;
        $expected['password'] = '[REDACTED]';

        $this->assertEquals($expected, $safe);
        $this->assertEquals($safe, WorkflowReportSanitizer::sanitize($safe));
    }

    public function test_json_serializable_lists_keep_their_list_shape_and_hidden_data_is_not_inspected(): void
    {
        $value = new class implements \JsonSerializable
        {
            private string $hidden = 'do-not-inspect-this12345';

            public function jsonSerialize(): mixed
            {
                return [['id' => 123, 'key' => 'pipeline_id', 'value' => 456]];
            }
        };

        $this->assertSame('[{"id":123,"key":"pipeline_id","value":456}]', json_encode(WorkflowReportSanitizer::sanitize($value)));
    }
}

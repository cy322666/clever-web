<?php

namespace Tests\Feature\Finder;

use App\Models\Integrations\Finder\Setting;
use App\Services\amoCRM\Client;
use App\Services\Finder\WebhookConnection;
use Illuminate\Validation\ValidationException;
use Tests\Support\FinderDatabase;
use Tests\TestCase;

class WebhookConnectionTest extends TestCase
{
    private function connection(Client $client): WebhookConnection
    {
        return new class($client) extends WebhookConnection
        {
            public function __construct(private Client $testClient) {}

            protected function client(Setting $setting): Client
            {
                return $this->testClient;
            }
        };
    }

    public function test_connection_registers_only_its_own_hook_and_reads_back_both_events(): void
    {
        $setting = FinderDatabase::prepare();
        config(['workflow-webhooks.public_url' => 'https://finder.example.test']);
        $client = $this->createMock(Client::class);
        $connection = $this->connection($client);
        $url = $connection->url($setting);
        $reads = 0;
        $client->expects($this->exactly(3))->method('requestV4')->willReturnCallback(function ($method, $path, $payload = []) use ($url, &$reads) {
            $this->assertSame('/api/v4/webhooks', $path);
            if ($method === 'POST') {
                $this->assertSame(['destination' => $url, 'settings' => WebhookConnection::EVENTS], $payload);

                return [];
            }
            $this->assertSame('GET', $method);
            if (++$reads === 1) {
                return ['_embedded' => ['webhooks' => [['destination' => 'https://other.example.test/hook', 'settings' => ['add_lead']]]]];
            }

            return ['_embedded' => ['webhooks' => [
                ['destination' => 'https://other.example.test/hook', 'settings' => ['add_lead']],
                ['destination' => $url, 'settings' => WebhookConnection::EVENTS, 'disabled' => false],
            ]]];
        });
        $connection->connect($setting);
        $this->assertNotNull($setting->refresh()->connected_at);
    }

    public function test_retry_reads_an_existing_complete_hook_without_posting_again(): void
    {
        $setting = FinderDatabase::prepare();
        config(['workflow-webhooks.public_url' => 'https://finder.example.test']);
        $client = $this->createMock(Client::class);
        $connection = $this->connection($client);
        $client->expects($this->once())->method('requestV4')->with('GET', '/api/v4/webhooks')
            ->willReturn(['_embedded' => ['webhooks' => [['destination' => $connection->url($setting), 'settings' => WebhookConnection::EVENTS]]]]);
        $connection->connect($setting);
        $this->assertNotNull($setting->refresh()->connected_at);
    }

    public function test_connection_does_not_claim_success_without_outgoing_event(): void
    {
        $setting = FinderDatabase::prepare();
        config(['workflow-webhooks.public_url' => 'https://finder.example.test']);
        $client = $this->createMock(Client::class);
        $connection = $this->connection($client);
        $client->method('requestV4')->willReturn(['_embedded' => ['webhooks' => [['destination' => $connection->url($setting), 'settings' => ['add_message']]]]]);
        try {
            $connection->connect($setting);
            $this->fail('Missing outgoing subscription must be rejected.');
        } catch (ValidationException) {
            $this->assertNull($setting->refresh()->connected_at);
        }
    }
}

<?php

namespace App\Services\AlfaCRM\Integrations\Models;

use App\Services\AlfaCRM\Client;

class Status
{
    public function __construct(private Client $client) {}

    public function all()
    {
        $response = $this->client
            ->http
            ->post("https://{$this->client->domain}.".$this->client::$baseUrl.'lead-status/index', [
                'headers' => $this->client->headers(),
                'body' => json_encode([
                    "page"      => 0,
                ]),
            ]);

        return json_decode($response->getBody()->getContents())->items;
    }
}

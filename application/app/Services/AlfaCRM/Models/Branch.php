<?php

namespace App\Services\AlfaCRM\Models;

use App\Services\AlfaCRM\Client;

class Branch
{
    public function __construct(private Client $client) {}

    public function all()
    {
        $response = $this->client
            ->http
            ->post("https://{$this->client->domain}.".$this->client::$baseUrl.'branch/index', [
                'headers' => $this->client->headers(),
                'body' => json_encode([
                    "is_active" => 1,
                    "page"      => 0,
                ]),
            ]);

        return json_decode($response->getBody()->getContents())->items;
    }
}

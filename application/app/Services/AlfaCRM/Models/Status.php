<?php

namespace App\Services\AlfaCRM\Models;

use App\Services\AlfaCRM\Client;

class Status
{
    public function __construct(public Client $client) {}

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function all()
    {
        $response = $this->client
            ->http
            ->post('https://'.$this->client->domain.'.'.$this->client::$baseUrl.'lead-status/index', [
                'headers' => $this->client->headers(),
                'body' => json_encode([
                    "page"      => 0,
                ]),
            ]);

        return json_decode($response->getBody()->getContents())->items;
    }
}

<?php

namespace App\Services\AlfaCRM\Models;

use App\Services\AlfaCRM\Client;

class Lesson
{
    public const LESSON_TYPE_ID = 3;

    public const LESSON_CAME_TYPE_ID = 3;
    public const LESSON_OMISSION_TYPE_ID = 2;

    public function __construct(private Client $client) {}

    public function get(int $id, int $status = 3)
    {
        $response = $this->client
            ->http
            ->post("https://{$this->client->domain}.".$this->client::$baseUrl.$this->client->branchId.'/lesson/index', [
                'headers' => $this->client->headers(),
                'json' => [
                    "id" => $id,
                    "status" => $status,
                ],
            ]);

        return json_decode($response->getBody()->getContents())->items[0] ?? false;
    }
}

<?php

namespace App\Services\AlfaCRM;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class Client
{
    public ?string $domain;
    public ?string $email;
    public ?string $apikey;

    public int $branchId = 1;

    public ?string $token = null;

    public \GuzzleHttp\Client $http;
    public Model $storage;

    public bool $auth = false;

    public static string $baseUrl = 's20.online/v2api/';

    public function __construct(Model $storage)
    {
        $this->http = new \GuzzleHttp\Client();

        $this->domain = $storage->subdomain;
        $this->email  = $storage->client_id;
        $this->apikey = $storage->code;

        $this->storage = $storage;
    }

    public function init(): static
    {
        $response = $this
            ->http
            ->post("https://{$this->domain}.".self::$baseUrl.'auth/login', [
                'headers' => $this->headers(),
                'body' => json_encode([
                    'email'   => $this->email,
                    'api_key' => $this->apikey,
                ]),
            ]);

        try {

            $this->token = json_decode($response->getBody()->getContents())->token;

            $this->auth = true;

        } catch (\Throwable $exception) {

        }
        return $this;
    }

    public function headers(): array
    {
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json'];

        if ($this->token) {

            $headers = array_merge(['X-ALFACRM-TOKEN' => $this->token], $headers);
        }
        return $headers;
    }
}

<?php

namespace App\Services\Vetmanager;

use App\Models\Core\Account;
use App\Services\amoCRM\Client;

class AmoCrmGateway
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function request(
        Account $account,
        string $method,
        string $path,
        array $payload = [],
        array $query = [],
    ): array {
        return (new Client($account))->requestV4($method, $path, $payload, $query);
    }
}

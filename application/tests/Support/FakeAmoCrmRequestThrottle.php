<?php

namespace Tests\Support;

use App\Models\Core\Account;
use App\Services\amoCRM\AmoCrmRequestThrottle;

/** Other tests fake the network; never reach Redis or spend real time on pacing. */
class FakeAmoCrmRequestThrottle extends AmoCrmRequestThrottle
{
    public array $acquisitions = [];

    public array $cooldowns = [];

    public function acquire(Account $account): void
    {
        $this->acquisitions[] = $account;
    }

    public function cooldown(Account $account, ?string $retryAfter = null, int $attempt = 1): void
    {
        $this->cooldowns[] = [$account, $retryAfter, $attempt];
    }
}

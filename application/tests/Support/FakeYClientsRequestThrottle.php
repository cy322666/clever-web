<?php

namespace Tests\Support;

use App\Services\YClients\YClientsRequestThrottle;
use LogicException;

/** Tests fake HTTP and Redis; retain admission and retry logic without real pacing. */
class FakeYClientsRequestThrottle extends YClientsRequestThrottle
{
    protected function jitterSeconds(): int
    {
        return 0;
    }

    protected function evaluate(string $script, array $keys, array $arguments): mixed
    {
        return match ($script) {
            self::ACQUIRE_SCRIPT => 0,
            self::COOLDOWN_SCRIPT => 1,
            self::RETRY_SCRIPT => (int) ceil(max(
                $arguments[0],
                $arguments[2] ? max(0, $arguments[1] - microtime(true) * 1000) : $arguments[1],
            ) / 1000),
            default => throw new LogicException('Unexpected YClients admission script.'),
        };
    }
}

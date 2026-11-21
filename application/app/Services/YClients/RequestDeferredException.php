<?php

namespace App\Services\YClients;

use RuntimeException;
use Throwable;

final class RequestDeferredException extends RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfterSeconds = 30, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

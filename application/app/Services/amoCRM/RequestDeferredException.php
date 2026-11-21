<?php

namespace App\Services\amoCRM;

use RuntimeException;
use Throwable;

/** No business write was sent, or the API explicitly rejected it with 429. */
final class RequestDeferredException extends RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfterSeconds = 30,
        bool $rateLimited = false, ?Throwable $previous = null)
    {
        parent::__construct($message, $rateLimited ? 429 : 0, $previous);
    }
}

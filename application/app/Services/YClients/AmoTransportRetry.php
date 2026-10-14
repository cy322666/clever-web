<?php

namespace App\Services\YClients;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

final class AmoTransportRetry
{
    private const DELAYS = [5, 15];

    /**
     * Only wrap reads or updates to existing entities, never entity creation.
     */
    public static function run(callable $operation, array $context = []): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $operation($attempt);
            } catch (Throwable $exception) {
                $delay = self::DELAYS[$attempt - 1] ?? null;

                if ($delay === null || !self::isTransportError($exception)) {
                    throw $exception;
                }

                Log::warning('YClients amoCRM transport error, retrying safe operation.', [
                    ...$context,
                    'attempt' => $attempt,
                    'next_attempt' => $attempt + 1,
                    'retry_delay_seconds' => $delay,
                    'error' => $exception->getMessage(),
                ]);

                Sleep::for($delay)->seconds();
            }
        }
    }

    public static function isTransportError(Throwable $exception): bool
    {
        return $exception instanceof ConnectionException
            || self::isTransportMessage($exception->getMessage());
    }

    public static function isTransportMessage(string $message): bool
    {
        return preg_match('/Invalid API response \(non JSON\), code: (?:0|500|502|503|504)(?=\s|$)/', $message) === 1
            || preg_match('/amoCRM API v4 error: .* returned (?:500|502|503|504):/', $message) === 1
            || preg_match('/curl error (?:6|7|28)(?=\D|$)/i', $message) === 1
            || preg_match('/timed[ -]?out|connection refused|could not resolve/i', $message) === 1;
    }
}

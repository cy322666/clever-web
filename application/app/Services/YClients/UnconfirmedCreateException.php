<?php

namespace App\Services\YClients;

use RuntimeException;
use Throwable;

final class UnconfirmedCreateException extends RuntimeException
{
    public function __construct(string $entity, ?Throwable $previous = null)
    {
        parent::__construct(
            'YClients '.$entity.' create outcome is unconfirmed. Reconcile the existing CRM entity before creating again.',
            0,
            $previous,
        );
    }
}

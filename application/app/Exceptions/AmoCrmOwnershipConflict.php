<?php

namespace App\Exceptions;

final class AmoCrmOwnershipConflict extends \RuntimeException
{
    public function __construct(public readonly string $domain, public readonly array $userIds, public readonly array $accountIds = [])
    {
        parent::__construct('Several platform users are linked to this amoCRM account.');
    }
}

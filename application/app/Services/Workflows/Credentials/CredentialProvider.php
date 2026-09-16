<?php

namespace App\Services\Workflows\Credentials;

interface CredentialProvider
{
    public static function label(): string;

    public static function schema(bool $editing = false): array;

    /** @return array{name: string, secret: string}|null Null preserves an existing connection. */
    public static function prepare(array $data, bool $editing = false): ?array;
}

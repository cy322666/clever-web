<?php

namespace Tests\Unit\Auth;

use App\Support\Auth\AccountEmail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountEmailTest extends TestCase
{
    #[DataProvider('invalidEmails')]
    public function test_it_rejects_values_that_are_not_real_email_addresses(mixed $email): void
    {
        $this->assertFalse(AccountEmail::isValid($email));
    }

    public static function invalidEmails(): array
    {
        return [
            'empty' => [''],
            'array payload' => [['user@example.com']],
            'plain text' => ['Иван Иванов'],
            'missing domain' => ['user@'],
            'domain without public suffix' => ['user@company'],
            'spaces' => ['user name@example.com'],
            'line break' => ["user@example.com\nBcc: attacker@example.com"],
        ];
    }

    #[DataProvider('validEmails')]
    public function test_it_accepts_valid_addresses(string $email): void
    {
        $this->assertTrue(AccountEmail::isValid($email));
    }

    public static function validEmails(): array
    {
        return [
            'regular' => ['user@example.com'],
            'tagged' => ['user+crm@example.co.uk'],
        ];
    }

    public function test_it_normalizes_email_before_validation_and_storage(): void
    {
        $this->assertSame('user@example.com', AccountEmail::normalize('  User@Example.COM  '));
        $this->assertTrue(AccountEmail::isValid('  User@Example.COM  '));
    }
}

<?php

namespace Tests\Unit\YClients;

use App\Services\YClients\Contacts;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ContactsTest extends TestCase
{
    public function test_phone_search_uses_last_ten_digits(): void
    {
        $this->assertSame('9261234567', Contacts::clearPhone('+7 (926) 123-45-67'));
        $this->assertSame('9261234567', Contacts::clearPhone('8 926 123 45 67'));
    }

    public function test_phone_store_uses_stable_russian_format(): void
    {
        $this->assertSame('+79261234567', Contacts::clearPhone('8 926 123 45 67', true));
        $this->assertSame('+79261234567', Contacts::clearPhone('79261234567', true));
        $this->assertSame('+79261234567', Contacts::clearPhone('9261234567', true));
    }

    public function test_email_normalization_trims_and_lowercases(): void
    {
        $method = (new ReflectionClass(Contacts::class))->getMethod('normalizeEmail');

        $this->assertSame('client@example.com', $method->invoke(null, ' Client@Example.COM '));
        $this->assertNull($method->invoke(null, 'not-email'));
    }
}

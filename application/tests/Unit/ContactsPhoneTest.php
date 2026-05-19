<?php

namespace Tests\Unit;

use App\Services\amoCRM\Models\Contacts;
use PHPUnit\Framework\TestCase;

class ContactsPhoneTest extends TestCase
{
    public function test_clear_phone_removes_plus_for_search(): void
    {
        $this->assertSame('79991234567', Contacts::clearPhone('+7 (999) 123-45-67'));
    }

    public function test_clear_phone_can_keep_leading_plus_for_storage(): void
    {
        $this->assertSame('+79991234567', Contacts::clearPhone('+7 (999) 123-45-67', true));
    }
}

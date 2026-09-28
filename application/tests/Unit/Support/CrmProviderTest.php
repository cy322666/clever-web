<?php

namespace Tests\Unit\Support;

use App\Support\Crm\CrmProvider;
use PHPUnit\Framework\TestCase;

class CrmProviderTest extends TestCase
{
    public function test_provider_selects_the_correct_oauth_host(): void
    {
        $this->assertSame('https://www.amocrm.ru/oauth/', CrmProvider::authorizationUrl('amocrm'));
        $this->assertSame('https://www.kommo.com/oauth', CrmProvider::authorizationUrl('kommo'));
        $this->assertSame('https://www.amocrm.ru/oauth/', CrmProvider::authorizationUrl(null));
    }
}

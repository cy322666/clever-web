<?php

namespace Tests\Unit\Support;

use App\Support\Onboarding\IndustryProfile;
use PHPUnit\Framework\TestCase;

class IndustryProfileTest extends TestCase
{
    public function test_industry_changes_the_recommended_integrations(): void
    {
        $this->assertSame('yclients', IndustryProfile::recommendedApps('beauty')[0]);
        $this->assertSame('vetmanager', IndustryProfile::recommendedApps('veterinary')[0]);
        $this->assertSame('tilda', IndustryProfile::recommendedApps('education')[0]);
        $this->assertSame([], IndustryProfile::recommendedApps('other'));
    }
}

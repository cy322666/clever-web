<?php

namespace Tests\Unit;

use Tests\TestCase;

class IntegrationBrandAssetsTest extends TestCase
{
    public function test_catalog_logos_are_bundled_local_assets(): void
    {
        foreach (config('integrations.definitions') as $name => $definition) {
            $logo = $definition['logo'] ?? '';

            $this->assertStringStartsWith('logo/', $logo, $name);
            $this->assertStringNotContainsString('..', $logo, $name);
            $this->assertFileExists(public_path($logo), $name);
            $this->assertGreaterThan(0, filesize(public_path($logo)), $name);
            $this->assertArrayNotHasKey('icon', $definition, $name);
        }
    }

    public function test_service_integrations_have_their_own_logos(): void
    {
        foreach (['tilda', 'yclients', 'sqns', 'vetmanager', 'import-excel'] as $name) {
            $this->assertStringStartsWith('logo/integrations/', config("integrations.definitions.{$name}.logo"));
        }

        foreach (['finder', 'distribution', 'workflows'] as $name) {
            $this->assertSame('logo/clever_mini_logo.png', config("integrations.definitions.{$name}.logo"));
        }
    }
}

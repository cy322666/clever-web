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

    }

    public function test_clever_widgets_use_distinct_original_vector_icons(): void
    {
        $logos = [];
        foreach (['finder', 'distribution', 'workflows'] as $name) {
            $logo = config("integrations.definitions.{$name}.logo");
            $this->assertStringStartsWith('logo/widgets/', $logo);
            $this->assertStringEndsWith('.svg', $logo);

            $dom = new \DOMDocument;
            $this->assertTrue($dom->load(public_path($logo), LIBXML_NONET));
            $this->assertSame('0 0 96 96', $dom->documentElement->getAttribute('viewBox'));
            $this->assertSame(0, $dom->getElementsByTagName('script')->length);
            $this->assertSame(0, $dom->getElementsByTagName('image')->length);
            $logos[] = hash_file('sha256', public_path($logo));
        }
        $this->assertCount(3, array_unique($logos));
    }
}

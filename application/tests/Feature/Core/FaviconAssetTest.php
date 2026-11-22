<?php

namespace Tests\Feature\Core;

use DOMDocument;
use DOMXPath;
use Tests\TestCase;

class FaviconAssetTest extends TestCase
{
    public function test_filament_login_uses_the_clever_favicon(): void
    {
        $this->withoutVite();

        $response = $this->get('/panel/login')->assertOk();

        $this->assertCleverIcons($response->getContent());
    }


    public function test_installation_and_error_pages_use_the_same_favicon(): void
    {
        config(['widget_lifecycle.install_status_store' => 'array']);

        $installation = $this->get('/amocrm/installation/00000000-0000-4000-8000-000000000000')->assertOk();
        $this->assertCleverIcons($installation->getContent());

        $missing = $this->get('/_tests/missing-favicon-page')->assertNotFound();
        $this->assertCleverIcons($missing->getContent());
    }

    public function test_default_icons_match_versioned_assets(): void
    {
        $size = getimagesize(public_path('favicon-clevercrm-20261111.png'));
        $this->assertSame([64, 64, IMAGETYPE_PNG], array_slice($size, 0, 3));

        $favicon = file_get_contents(public_path('favicon-clevercrm-20261101.ico'));
        $this->assertSame("\x00\x00\x01\x00", substr($favicon, 0, 4));
        $imageOffset = unpack('Voffset', $favicon, 18)['offset'];
        $this->assertSame("\x28\x00\x00\x00", substr($favicon, $imageOffset, 4));
        $this->assertSame($favicon, file_get_contents(public_path('favicon.ico')));
        $appleIcon = public_path('apple-touch-icon-clevercrm-20261101.png');
        $this->assertSame(file_get_contents($appleIcon), file_get_contents(public_path('apple-touch-icon.png')));
    }

    private function assertCleverIcons(string $html): void
    {
        $document = new DOMDocument;
        @$document->loadHTML($html, LIBXML_NONET);
        $xpath = new DOMXPath($document);

        $icons = $xpath->query('//head/link[@rel="icon" or @rel="shortcut icon"]');
        $this->assertCount(1, $icons);
        $this->assertSame(asset('favicon-clevercrm-20261111.png'), $icons->item(0)->getAttribute('href'));
        $this->assertSame('image/png', $icons->item(0)->getAttribute('type'));
        $this->assertSame('64x64', $icons->item(0)->getAttribute('sizes'));

        $touchIcons = $xpath->query('//head/link[@rel="apple-touch-icon"]');
        $this->assertCount(1, $touchIcons);
        $this->assertSame(asset('apple-touch-icon-clevercrm-20261101.png'), $touchIcons->item(0)->getAttribute('href'));
    }
}

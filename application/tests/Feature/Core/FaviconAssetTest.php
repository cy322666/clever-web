<?php

namespace Tests\Feature\Core;

use Tests\TestCase;

class FaviconAssetTest extends TestCase
{
    public function test_filament_login_uses_immutable_clevercrm_favicon(): void
    {
        $response = $this->get('/panel/login');

        $response->assertOk();
        $response->assertSee(asset('favicon-clevercrm-20261101.ico'), false);
        $response->assertSee(asset('apple-touch-icon-clevercrm-20261101.png'), false);
        $response->assertDontSee('favicon-clevercrm-20260922.ico', false);
        $this->assertFileExists(public_path('favicon-clevercrm-20261101.ico'));
        $this->assertGreaterThan(0, filesize(public_path('favicon-clevercrm-20261101.ico')));
    }

    public function test_both_panel_providers_use_the_same_versioned_favicon(): void
    {
        foreach ([\App\Providers\Filament\AppPanelProvider::class, \App\Providers\Filament\CatalogPanelProvider::class] as $providerClass) {
            $provider = new $providerClass($this->app);
            $panel = $provider->panel(\Filament\Panel::make());

            $this->assertSame(
                asset('favicon-clevercrm-20261101.ico'),
                $panel->getFavicon(),
            );
        }
    }

    public function test_default_icons_match_versioned_assets(): void
    {
        $favicon = file_get_contents(public_path('favicon-clevercrm-20261101.ico'));
        $this->assertSame("\x00\x00\x01\x00", substr($favicon, 0, 4));
        $imageOffset = unpack('Voffset', $favicon, 18)['offset'];
        $this->assertSame("\x28\x00\x00\x00", substr($favicon, $imageOffset, 4));
        $this->assertSame($favicon, file_get_contents(public_path('favicon.ico')));
        $appleIcon = public_path('apple-touch-icon-clevercrm-20261101.png');
        $this->assertSame(file_get_contents($appleIcon), file_get_contents(public_path('apple-touch-icon.png')));
    }
}

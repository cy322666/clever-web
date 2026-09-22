<?php

namespace Tests\Feature\Core;

use Tests\TestCase;

class FaviconAssetTest extends TestCase
{
    public function test_filament_login_uses_immutable_clevercrm_favicon(): void
    {
        $response = $this->get('/panel/login');

        $response->assertOk();
        $response->assertSee(asset('favicon-clevercrm-20260922.ico'), false);
        $this->assertFileExists(public_path('favicon-clevercrm-20260922.ico'));
        $this->assertGreaterThan(0, filesize(public_path('favicon-clevercrm-20260922.ico')));
    }
}

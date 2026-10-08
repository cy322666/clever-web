<?php

namespace Tests\Feature\Core;

use App\Exceptions\Handler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class NotFoundPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false, 'alerts.integration_errors.enabled' => false]);
        app()->setLocale('ru');
    }

    public function test_unknown_url_uses_branded_page_with_real_404_status(): void
    {
        $this->get('/_tests/not-found-page-does-not-exist')->assertNotFound()
            ->assertSee('Страница не найдена')->assertDontSee('В приложение')->assertSee('Назад')
            ->assertSee('Поддержка')->assertSee('alt="Clever"', false)
            ->assertSee('/logo/full_logo.png', false)->assertSee('noindex, nofollow')
            ->assertDontSee('Laravel')->assertDontSee('Not Found')->assertDontSee('CleverCRM');
    }

    public function test_missing_record_uses_same_page_without_private_details(): void
    {
        $exception = (new ModelNotFoundException)->setModel('PrivateCustomerModel', ['private-record']);
        $response = app(Handler::class)->render(Request::create('/panel/missing-record'), $exception);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('Страница не найдена', $response->getContent());
        $this->assertStringNotContainsString('PrivateCustomerModel', $response->getContent());
        $this->assertStringNotContainsString('private-record', $response->getContent());
    }

    public function test_json_errors_stay_machine_readable(): void
    {
        $this->getJson('/api/_tests/not-found-page-does-not-exist')->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')->assertJsonStructure(['message'])
            ->assertDontSee('<html', false)->assertDontSee('Поддержка');
    }

    public function test_page_works_without_database_or_compiled_view_cache(): void
    {
        config(['database.default' => 'nonexistent', 'view.compiled' => '/nonexistent/view-cache']);
        $response = app(Handler::class)->render(Request::create('/missing'), new NotFoundHttpException('private-message'));
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('Страница не найдена', $response->getContent());
        $this->assertStringNotContainsString('private-message', $response->getContent());
    }

    public function test_page_respects_interface_language(): void
    {
        app()->setLocale('en');
        $response = app(Handler::class)->render(Request::create('/missing'), new NotFoundHttpException);
        $this->assertStringContainsString('<html lang="en">', $response->getContent());
        $this->assertStringContainsString('Page not found', $response->getContent());
        $this->assertStringContainsString('Go back', $response->getContent());
        $this->assertStringNotContainsString('Back to the app', $response->getContent());
        $this->assertStringNotContainsString('Страница не найдена', $response->getContent());
    }

    #[DataProvider('backLinks')]
    public function test_back_link_is_safe(string $referer, string $expected): void
    {
        $request = Request::create('https://platform.example/missing', server: ['HTTP_REFERER' => $referer]);
        $this->app->instance('request', $request);
        $response = app(Handler::class)->render($request, new NotFoundHttpException);
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $this->assertSame($expected, $dom->getElementById('not-found-back')->getAttribute('href'));
    }

    public static function backLinks(): array
    {
        return [
            'same origin' => ['https://platform.example/panel/workflows?page=2', '/panel/workflows?page=2'],
            'direct visit' => ['', '/panel/dashboard'],
            'external' => ['https://untrusted.example/login', '/panel/dashboard'],
            'host spoof' => ['https://platform.example.evil.test/login', '/panel/dashboard'],
            'scheme relative' => ['https://platform.example//evil.test', '/panel/dashboard'],
            'backslash' => ['https://platform.example/\\evil.test', '/panel/dashboard'],
            'same failing page' => ['https://platform.example/missing', '/panel/dashboard'],
            'unsafe attribute' => ['https://platform.example/panel?q="<svg/onload=alert(1)>', '/panel?q="<svg/onload=alert(1)>'],
        ];
    }

    public function test_navigation_escapes_iframe_and_support_does_not_share_referrer(): void
    {
        $response = app(Handler::class)->render(Request::create('/missing'), new NotFoundHttpException);
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $this->assertNull($dom->getElementById('not-found-home'));
        $back = $dom->getElementById('not-found-back');
        $this->assertSame('/panel/dashboard', $back->getAttribute('href'));
        $this->assertSame('_top', $back->getAttribute('target'));
        $support = $dom->getElementById('not-found-support');
        $this->assertSame('https://button.amocrm.ru/ddrllz', $support->getAttribute('href'));
        $this->assertSame('_blank', $support->getAttribute('target'));
        $this->assertSame('noopener noreferrer', $support->getAttribute('rel'));
    }
}

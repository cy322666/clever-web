<?php

namespace Tests\Feature\Core;

use App\Exceptions\Handler;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ServerErrorPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false, 'alerts.integration_errors.enabled' => false]);
        app()->setLocale('ru');
    }

    public function test_unhandled_http_error_uses_customer_page_and_keeps_500_status(): void
    {
        Route::get('/_tests/server-error', fn () => throw new RuntimeException('private-db-password'));

        $response = $this->get('/_tests/server-error');

        $response->assertStatus(500)
            ->assertSee('Возникла ошибка')
            ->assertSee('Назад')
            ->assertSee('Поддержка')
            ->assertSee('https://button.amocrm.ru/ddrllz', false)
            ->assertDontSee('private-db-password')
            ->assertDontSee('Laravel')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_browser_never_receives_debug_trace_even_if_debug_is_enabled(): void
    {
        config(['app.debug' => true]);
        $response = app(Handler::class)->render(Request::create('https://platform.example/panel/broken'),
            new RuntimeException('private-token-secret'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('Возникла ошибка', $response->getContent());
        $this->assertStringNotContainsString('private-token-secret', $response->getContent());
        $this->assertStringNotContainsString('stack', $response->getContent());
    }

    public function test_explicit_server_errors_keep_original_status_and_retry_header(): void
    {
        $response = app(Handler::class)->render(Request::create('/panel/broken'),
            new HttpException(503, 'private-service-name', null, ['Retry-After' => '120']));

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('120', $response->headers->get('Retry-After'));
        $this->assertStringContainsString('Ошибка 503', $response->getContent());
        $this->assertStringNotContainsString('private-service-name', $response->getContent());
    }

    public function test_database_outage_renders_without_database_or_compiled_blade_cache(): void
    {
        config(['database.default' => 'nonexistent-connection', 'view.compiled' => '/nonexistent/unwritable/view-cache']);
        $response = app(Handler::class)->render(Request::create('/panel/broken'),
            new QueryException('pgsql', 'select secret from users', [], new RuntimeException('Connection refused')));

        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringContainsString('Возникла ошибка', $response->getContent());
        $this->assertStringNotContainsString('select secret', $response->getContent());
        $this->assertStringNotContainsString('Service temporarily unavailable', $response->getContent());
    }

    public function test_json_and_api_requests_remain_machine_readable(): void
    {
        foreach ([['/api/test', []], ['/livewire/update', ['HTTP_ACCEPT' => 'application/json']]] as [$url, $headers]) {
            $response = app(Handler::class)->render(Request::create($url, 'POST', server: $headers),
                new RuntimeException('private-token'));
            $this->assertSame(500, $response->getStatusCode());
            $this->assertSame('application/json', $response->headers->get('Content-Type'));
            $this->assertSame(['message' => 'Server Error'], json_decode($response->getContent(), true));
        }

        $response = app(Handler::class)->render(Request::create('/api/test'),
            new QueryException('pgsql', 'select secret', [], new RuntimeException('Connection refused')));
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame(['message' => 'Service temporarily unavailable'], json_decode($response->getContent(), true));
    }

    public function test_client_errors_and_validation_are_not_replaced_with_server_error_page(): void
    {
        $response = app(Handler::class)->render(Request::create('/panel/not-found'), new HttpException(404));
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString('Возникла ошибка', $response->getContent());

        Route::post('/_tests/validate', fn (Request $request) => $request->validate(['email' => 'required|email']));
        $this->postJson('/_tests/validate', ['email' => 'not-email'])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    #[DataProvider('backLinks')]
    public function test_back_link_only_uses_safe_same_origin_page(string $referer, string $expected): void
    {
        $request = Request::create('https://platform.example/panel/broken', server: ['HTTP_REFERER' => $referer]);
        $response = app(Handler::class)->render($request, new RuntimeException('failure'));
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $this->assertSame($expected, $document->getElementById('server-error-back')->getAttribute('href'));
    }

    public static function backLinks(): array
    {
        return [
            'previous page' => ['https://platform.example/panel/workflows?page=2', '/panel/workflows?page=2'],
            'direct navigation' => ['', '/panel/dashboard'],
            'external page' => ['https://untrusted.example/phishing', '/panel/dashboard'],
            'host prefix spoof' => ['https://platform.example.evil.test/panel', '/panel/dashboard'],
            'scheme relative' => ['https://platform.example//untrusted.example', '/panel/dashboard'],
            'backslash' => ['https://platform.example/\\untrusted.example', '/panel/dashboard'],
            'same failing page' => ['https://platform.example/panel/broken', '/panel/dashboard'],
            'escaped attribute' => ['https://platform.example/panel/search?q="<svg/onload=alert(1)>', '/panel/search?q="<svg/onload=alert(1)>'],
        ];
    }

    public function test_support_uses_requested_link_and_does_not_share_opener_or_referrer(): void
    {
        $response = app(Handler::class)->render(Request::create('/panel/broken'), new RuntimeException('failure'));
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $links = $document->getElementsByTagName('a');
        $this->assertSame(2, $links->count());
        $this->assertSame('_top', $links->item(0)->getAttribute('target'));
        $this->assertSame('https://button.amocrm.ru/ddrllz', $links->item(1)->getAttribute('href'));
        $this->assertSame('_blank', $links->item(1)->getAttribute('target'));
        $this->assertSame('noopener noreferrer', $links->item(1)->getAttribute('rel'));
    }

    public function test_error_page_respects_interface_language(): void
    {
        app()->setLocale('en');
        $response = app(Handler::class)->render(Request::create('/panel/broken'), new RuntimeException('failure'));
        $this->assertStringContainsString('Something went wrong', $response->getContent());
        $this->assertStringContainsString('<html lang="en">', $response->getContent());
        $this->assertStringNotContainsString('Возникла ошибка', $response->getContent());
    }
}

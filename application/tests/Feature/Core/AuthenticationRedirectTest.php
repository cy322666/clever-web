<?php

namespace Tests\Feature\Core;

use App\Exceptions\Handler;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Tests\TestCase;

class AuthenticationRedirectTest extends TestCase
{
    public function test_guest_without_a_middleware_redirect_uses_the_real_panel_login(): void
    {
        $request = Request::create('/panel/workflows');
        $this->app->instance('request', $request);
        $response = app(Handler::class)->render($request, new AuthenticationException);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(route('filament.app.auth.login'), $response->headers->get('Location'));
    }

    public function test_json_authentication_failure_stays_401(): void
    {
        $request = Request::create('/panel/workflows', server: ['HTTP_ACCEPT' => 'application/json']);
        $response = app(Handler::class)->render($request, new AuthenticationException);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(['message' => 'Unauthenticated.'], json_decode($response->getContent(), true));
        $this->assertFalse($response->headers->has('Location'));
    }

    public function test_an_explicit_panel_redirect_is_preserved(): void
    {
        $response = app(Handler::class)->render(
            Request::create('/private'),
            new AuthenticationException(redirectTo: '/custom-panel/login'),
        );

        $this->assertSame(url('/custom-panel/login'), $response->headers->get('Location'));
    }

    public function test_workflow_list_redirects_guests_instead_of_throwing_a_server_error(): void
    {
        $this->get('/panel/workflows')->assertRedirect(route('filament.app.auth.login'));
        $this->assertSame(url('/panel/workflows'), session('url.intended'));
        $this->getJson('/panel/workflows')->assertUnauthorized();
    }
}

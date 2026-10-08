<?php

namespace App\Exceptions;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->renderable(function (QueryException $e, $request) {
            if (!$this->isDbConnectionRefused($e)) {
                return null;
            }

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Service temporarily unavailable',
                ], Response::HTTP_SERVICE_UNAVAILABLE);
            }

            return $this->serverErrorResponse($request, Response::HTTP_SERVICE_UNAVAILABLE);
        });
    }

    protected function renderExceptionResponse($request, Throwable $e)
    {
        $status = $this->isHttpException($e) ? $e->getStatusCode() : Response::HTTP_INTERNAL_SERVER_ERROR;

        if ($status >= 500) {
            if ($this->shouldReturnJson($request, $e) || $request->is('api/*')) {
                return $this->prepareJsonResponse($request, $e);
            }

            return $this->serverErrorResponse($request, $status, $this->isHttpException($e) ? $e->getHeaders() : []);
        }

        return parent::renderExceptionResponse($request, $e);
    }

    private function serverErrorResponse($request, int $status, array $headers = []): Response
    {
        // A plain PHP view works even when Blade's compiled-view directory is unavailable.
        return response()->view('errors.server', [
            'statusCode' => $status,
            'locale' => app()->getLocale(),
            'backUrl' => $this->safeBackUrl($request),
        ], $status, array_merge($headers, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ]));
    }

    private function safeBackUrl($request): string
    {
        $fallback = '/panel/dashboard';
        $previous = (string) $request->headers->get('referer', '');
        $origin = $request->getSchemeAndHttpHost();

        if (!str_starts_with($previous, $origin.'/') || str_contains($previous, '\\')
            || preg_match('/[\x00-\x20]/', $previous)) {
            return $fallback;
        }

        $path = substr($previous, strlen($origin));

        return str_starts_with($path, '//') || $path === $request->getRequestUri() ? $fallback : $path;
    }

    public function report(Throwable $e): void
    {
        if ($this->isTelescopeStorageFailure($e)) {
            return;
        }

        parent::report($e);
    }

    private function isDbConnectionRefused(Throwable $e): bool
    {
        if (!$e instanceof QueryException) {
            return false;
        }

        $message = mb_strtolower($e->getMessage());

        return str_contains($message, 'sqlstate[hy000] [2002]')
            || str_contains($message, 'connection refused')
            || str_contains($message, 'no route to host');
    }

    private function isTelescopeStorageFailure(Throwable $e): bool
    {
        if (!$e instanceof QueryException) {
            return false;
        }

        $message = mb_strtolower($e->getMessage());

        return str_contains($message, 'telescope_entries')
            || str_contains($message, 'telescope_entries_tags');
    }
}

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

            return response('Service temporarily unavailable', Response::HTTP_SERVICE_UNAVAILABLE);
        });
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

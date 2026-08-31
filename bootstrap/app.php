<?php

use App\Http\Middleware\EnsureDeveloperToolsEnabled;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'developer' => EnsureDeveloperToolsEnabled::class,
            'role' => EnsureUserHasRole::class,
            'active' => EnsureUserIsActive::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson() || $request->ajax(),
        );

        // Every AJAX failure returns the standard ApiResponse envelope, so the
        // front-end helper can surface errors without per-endpoint handling.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson() || $request->ajax())) {
                return null;
            }

            return match (true) {
                $e instanceof ValidationException => ApiResponse::validationError($e->errors(), $e->getMessage()),
                $e instanceof AuthenticationException => ApiResponse::unauthorized(),
                $e instanceof AuthorizationException => ApiResponse::forbidden($e->getMessage()),
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => ApiResponse::notFound(),
                $e instanceof HttpExceptionInterface => ApiResponse::error($e->getMessage() ?: 'Request failed.', null, $e->getStatusCode()),
                default => ApiResponse::serverError(
                    config('app.debug') ? $e->getMessage() : 'An unexpected error occurred.'
                ),
            };
        });
    })->create();

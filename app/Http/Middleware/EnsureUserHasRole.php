<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Route guard: `role:admin` or `role:admin,manager`.
 *
 * Phase 16 replaces coarse role checks with full policies; this is the
 * minimum needed to keep the back office closed in Phase 1.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role->value, $roles, true)) {
            if ($request->expectsJson()) {
                return ApiResponse::forbidden();
            }

            throw new AccessDeniedHttpException('You are not allowed to access this area.');
        }

        return $next($request);
    }
}

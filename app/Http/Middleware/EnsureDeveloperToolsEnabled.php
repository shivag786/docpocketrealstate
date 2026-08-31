<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the developer tools behind `config('company.developer_tools')`.
 *
 * WHY THIS IS MIDDLEWARE AND NOT AN `if` AROUND THE ROUTE DEFINITION:
 *
 * Wrapping the routes in a config check at registration time looks stricter —
 * the route would not exist at all — but it is checked ONCE, when routes are
 * registered, and `php artisan route:cache` then freezes that decision into
 * `bootstrap/cache/routes-*.php`. A deployment that cached its routes while the
 * flag was on would keep serving the reset page after the flag was turned off,
 * which is precisely the go-live moment this feature exists for.
 *
 * Read per request, the flag means what it says at all times.
 *
 * 404 rather than 403: a refusal confirms the page is there. Nothing about the
 * response should tell a stranger that a reset button exists behind a flag.
 */
class EnsureDeveloperToolsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('company.developer_tools'), 404);

        return $next($request);
    }
}

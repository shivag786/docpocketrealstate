<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Terminates the session of an operator who was deactivated while logged in.
 *
 * The login flow already rejects inactive accounts; this closes the window
 * where an admin disables an account that still holds a live session.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isActive()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $message = 'Your account has been deactivated.';

            if ($request->expectsJson()) {
                return ApiResponse::unauthorized($message);
            }

            return redirect()->route('login')->with('error', $message);
        }

        return $next($request);
    }
}

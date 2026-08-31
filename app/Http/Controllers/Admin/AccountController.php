<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdatePasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * The signed-in operator's own account.
 *
 * Scoped to self on purpose: this changes YOUR password and nobody else's.
 * There is no user-management screen in the product, and an admin who could
 * rewrite another operator's password from here would be able to lock them out
 * without leaving any trace that a person, rather than that operator, did it.
 */
class AccountController extends Controller
{
    public function editPassword(): View
    {
        return view('admin.settings.password');
    }

    public function updatePassword(UpdatePasswordRequest $request): RedirectResponse
    {
        // Writes the bcrypt hash AND the readable copy the client asked for —
        // see User::setPassword() and the add_password_plain_to_users migration.
        $request->user()->setPassword($request->validated()['password']);

        // The current session is re-keyed so the change cannot be replayed with
        // the old session id.
        //
        // NOTE: other signed-in sessions for this account are NOT ended. Doing
        // that needs Laravel's AuthenticateSession middleware, which this
        // application does not use — Auth::logoutOtherDevices() without it
        // rewrites the password hash and silently ends nothing. Rather than
        // tell the operator something untrue, the screen says plainly that
        // other sessions continue until they expire.
        $request->session()->regenerate();

        return redirect()
            ->route('admin.settings.password')
            ->with('success', 'Your password has been changed. Use it the next time you sign in.');
    }
}

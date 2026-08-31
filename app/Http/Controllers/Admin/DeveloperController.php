<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Developer\ResetSystemRequest;
use App\Services\SystemResetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Developer tools — the system reset.
 *
 * Three independent guards stand in front of this, because the action cannot be
 * undone and there is no backup step in the product:
 *
 *   1. `config('company.developer_tools')` — the routes are not registered at
 *      all when it is false, so the page 404s rather than merely refusing.
 *   2. The `role:admin,manager` middleware the whole admin group already carries.
 *   3. A typed confirmation, enforced server-side by ResetSystemRequest.
 *
 * The screen shows real row counts before anything happens, so the operator
 * confirms against what is actually there rather than against a promise.
 */
class DeveloperController extends Controller
{
    public function __construct(
        private readonly SystemResetService $reset,
    ) {}

    public function index(): View
    {
        return view('admin.settings.developer', [
            'counts' => $this->reset->preview(),
            'preserved' => SystemResetService::PRESERVES,
            'phrase' => ResetSystemRequest::PHRASE,
        ]);
    }

    public function performReset(ResetSystemRequest $request): RedirectResponse
    {
        $removed = $this->reset->reset($request->user());
        $total = array_sum($removed);

        if ($total === 0) {
            return redirect()
                ->route('admin.settings.developer')
                ->with('info', 'Nothing to clear — the system was already empty.');
        }

        return redirect()
            ->route('admin.settings.developer')
            ->with('success', sprintf(
                'System reset. %s %s removed across %d tables. Member codes restart at %s.',
                number_format($total),
                $total === 1 ? 'row was' : 'rows were',
                count(array_filter($removed)),
                config('members.code.prefix').config('members.code.start_at'),
            ));
    }
}

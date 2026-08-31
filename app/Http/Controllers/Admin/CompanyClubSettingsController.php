<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompanyClub\UpdateCompanyClubSettingsRequest;
use App\Services\CompanyClubReportService;
use App\Services\CompanyClubService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Company Club settings.
 *
 * Changing the display name is cosmetic and always safe. Changing the rate or
 * the level cap changes FUTURE calculations only - every run freezes both onto
 * its own row - and the screen says so, because an admin editing a rate is
 * entitled to know whether they are about to rewrite history. They are not.
 */
class CompanyClubSettingsController extends Controller
{
    public function __construct(
        private readonly CompanyClubService $club,
        private readonly CompanyClubReportService $reports,
    ) {}

    public function edit(): View
    {
        return view('admin.company-club.settings', [
            'settings' => $this->club->settings(),
            'calculatedPeriods' => $this->reports->calculatedPeriods(),
        ]);
    }

    public function update(UpdateCompanyClubSettingsRequest $request): RedirectResponse
    {
        $this->club->updateSettings($request->validated());

        return redirect()
            ->route('admin.company-club.settings')
            ->with('success', 'Company Club settings saved. Calculations already recorded are unchanged.');
    }
}

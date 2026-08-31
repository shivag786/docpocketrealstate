<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\UpdateCompanySettingsRequest;
use App\Http\Requests\Company\UpdateLetterFieldsRequest;
use App\Models\Member;
use App\Services\CompanySettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Company settings — the letterhead.
 *
 * Everything on this screen is identity, not calculation: nothing an admin
 * changes here can move a figure. The one edit with a consequence beyond
 * appearance is the designation list, and the screen says so, because removing
 * a rank stops it being offered to NEW members while leaving every member who
 * already holds it exactly as they are.
 */
class CompanySettingsController extends Controller
{
    public function __construct(
        private readonly CompanySettingsService $settings,
    ) {}

    public function edit(): View
    {
        $settings = $this->settings->current();

        return view('admin.settings.index', [
            'settings' => $settings,
            // How many members hold each rank. An admin about to delete a line
            // from the list is entitled to see that 40 people are on it.
            'designationCounts' => Member::query()
                ->selectRaw('designation, COUNT(*) as total')
                ->groupBy('designation')
                ->pluck('total', 'designation'),
        ]);
    }

    /**
     * Settings › Welcome Letter — which optional rows the letter prints.
     *
     * A member is shown alongside the switches so the operator sees the effect
     * on a real record rather than on placeholder text. The newest member is
     * used because it is the one most likely to be printed next.
     */
    public function letter(): View
    {
        return view('admin.settings.letter', [
            'settings' => $this->settings->current(),
            'labels' => self::LETTER_LABELS,
            'sample' => Member::query()->with('sponsor:id,name,member_code')->latest('id')->first(),
        ]);
    }

    public function updateLetter(UpdateLetterFieldsRequest $request): RedirectResponse
    {
        $this->settings->updateLetterFields($request->fields());

        return redirect()
            ->route('admin.settings.letter')
            ->with('success', 'Welcome letter updated. The next letter printed uses these rows.');
    }

    /**
     * How each toggle is described on screen: label, and what it prints.
     *
     * Here rather than in the Blade template so the wording and the config keys
     * are checked against each other in one place.
     */
    private const LETTER_LABELS = [
        'designation' => ['Designation', 'The rank the member holds, e.g. Sales Advisor.'],
        'mobile' => ['Contact number', "The member's mobile number."],
        'email' => ['Email', 'Prints "Not recorded" when the member has no email.'],
        'blood_group' => ['Blood group', 'Row is skipped entirely when the member has no blood group.'],
        'sponsor' => ['Sponsor ID', "The sponsor's name and member code. Skipped for a member with no sponsor."],
        'company' => ['Company name', 'A Company row inside the details table. The letterhead always shows it regardless.'],
    ];

    public function update(UpdateCompanySettingsRequest $request): RedirectResponse
    {
        $this->settings->update([
            ...$request->validated(),
            // Files are not part of validated() data in a usable form; take
            // them from the request itself.
            'logo' => $request->file('logo'),
            'signature' => $request->file('signature'),
        ]);

        return redirect()
            ->route('admin.settings.edit')
            ->with('success', 'Company settings saved.');
    }
}

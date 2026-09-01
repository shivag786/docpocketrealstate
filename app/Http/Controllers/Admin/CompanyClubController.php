<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LedgerStatus;
use App\Enums\RewardType;
use App\Http\Controllers\Controller;
use App\Models\CompanyClubCalculationRun;
use App\Models\Member;
use App\Models\RewardLedger;
use App\Services\CompanyClubReportService;
use App\Services\CompanyClubService;
use App\Services\CompanyClubTreeService;
use App\Services\RewardPaymentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * Company Club - the module's own screens.
 *
 * Deliberately NOT part of the Calculation Center and NOT part of the Upline
 * screens. Company Club is a separate calculation with its own rate, its own
 * eligibility rule and its own history, and mixing it into either of those would
 * make two unrelated things look like one.
 *
 * The workflow the screens enforce:
 *
 *   Preview  - shows the pool, the recipients and the working. Writes NOTHING.
 *   Calculate - the admin commits. Only now does a financial row exist.
 *   After that the month keeps itself current as sales arrive, and every screen
 *   states when it was last calculated and what the previous run said.
 */
class CompanyClubController extends Controller
{
    public function __construct(
        private readonly CompanyClubService $club,
        private readonly CompanyClubReportService $reports,
        private readonly CompanyClubTreeService $tree,
        private readonly RewardPaymentService $payments,
    ) {}

    private function period(Request $request): string
    {
        $period = (string) $request->query('period', now()->format('Y-m'));

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1
            ? $period
            : now()->format('Y-m');
    }

    /**
     * Overview - the state of the Club and of this month.
     */
    public function overview(Request $request): View
    {
        $period = $this->period($request);

        return view('admin.company-club.overview', $this->reports->overview($period) + [
            'periods' => $this->reports->calculatedPeriods(),
        ]);
    }

    /**
     * Network tree. Renders a shell; nodes arrive over AJAX one level at a time.
     */
    public function tree(): View
    {
        return view('admin.company-club.tree', [
            'settings' => $this->club->settings(),
            'network' => $this->tree->networkSummary(),
        ]);
    }

    /**
     * One level of the Company Club tree.
     *
     * No `member_id` means the members sitting directly under the Club itself.
     * The Club is not a member and has no row, so it is never returned here - it
     * is drawn by the view as the fixed root of the diagram.
     */
    public function treeChildren(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'member_id' => ['nullable', 'integer', 'exists:members,id'],
        ]);

        $level = 0;

        if (empty($validated['member_id'])) {
            $members = $this->tree->clubMembers();
        } else {
            $parent = Member::findOrFail($validated['member_id']);
            $members = $this->tree->childrenOf($parent);
            // The Club is never a level, so a member directly under it is
            // level 1 and depth counting starts from the member, not the Club.
            $level = $parent->ancestors()->count() + 1;
        }

        $childCounts = Member::query()
            ->whereIn('sponsor_id', $members->pluck('id'))
            ->selectRaw('sponsor_id, COUNT(*) as total')
            ->groupBy('sponsor_id')
            ->pluck('total', 'sponsor_id');

        return ApiResponse::success([
            'level' => $level,
            'nodes' => $members->map(fn (Member $member) => [
                'id' => $member->id,
                'member_code' => $member->member_code,
                'name' => $member->name,
                'status' => $member->status->value,
                'active' => $member->isActive(),
                'children' => (int) ($childCounts[$member->id] ?? 0),
                'level' => $level,
            ])->all(),
        ]);
    }

    /**
     * The Monthly Calculation screen.
     */
    public function calculateForm(Request $request): View
    {
        $period = $this->period($request);

        $preview = null;
        $error = null;

        try {
            $preview = $this->club->preview($period);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        return view('admin.company-club.calculate', [
            'period' => $period,
            'preview' => $preview,
            'previewError' => $error,
            'settings' => $this->club->settings(),
            'periods' => $this->reports->calculatedPeriods(),
            // Preview is open all month; committing is not. The reason is
            // rendered rather than the button silently vanishing.
            'calculationBlockedReason' => $this->club->calculationBlockedReason($period),
        ]);
    }

    /**
     * AJAX preview. Guaranteed not to write - see CompanyClubCalculationService.
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        try {
            $preview = $this->club->preview($validated['period']);
        } catch (Throwable $e) {
            return ApiResponse::error($e->getMessage());
        }

        $members = $preview['members'];

        return ApiResponse::success([
            'period' => $preview['period'],
            'total_sqft' => $preview['total_sqft'],
            'rate' => $preview['rate'],
            'pool_amount' => $preview['pool_amount'],
            'eligible_count' => $preview['eligible_count'],
            'equal_share' => $preview['equal_share'],
            'distributed_amount' => $preview['distributed_amount'],
            'residual_amount' => $preview['residual_amount'],
            'seller_count' => $preview['seller_count'],
            'excluded_seller_count' => $preview['excluded_seller_count'],
            'excluded_sqft' => $preview['excluded_sqft'],
            'calculated' => $preview['calculated'],
            'needs_recalculation' => $preview['needs_recalculation'],
            'locked' => $preview['locked'],
            'recipients' => collect($preview['recipients'])
                ->map(fn (array $row) => [
                    'member_id' => $row['member_id'],
                    'member_code' => $members[$row['member_id']]->member_code ?? '',
                    'name' => $members[$row['member_id']]->name ?? '',
                    'best_level' => $row['best_level'],
                    'path_count' => $row['path_count'],
                    'amount' => $row['amount'],
                ])->all(),
        ]);
    }

    /**
     * Commit the calculation. The first run for a period; refuses a second.
     */
    public function run(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        try {
            $run = $this->club->calculate($validated['period'], $request->user());
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.company-club.distribution', ['period' => $run->period])
            ->with('success', $this->runMessage($run));
    }

    /**
     * Rebuild a month that has already been calculated.
     */
    public function recalculate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        try {
            $run = $this->club->recalculate($validated['period'], $request->user());
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.company-club.distribution', ['period' => $run->period])
            ->with('success', $this->runMessage($run, true));
    }

    private function runMessage(CompanyClubCalculationRun $run, bool $rebuilt = false): string
    {
        return sprintf(
            '%s %s for %s: %s Sq.Ft. x %s = %s, shared equally between %d member%s at %s each.',
            $run->run_code,
            $rebuilt ? 'rebuilt' : 'calculated',
            $run->period,
            number_format((float) $run->total_sqft, 2),
            number_format((float) $run->rate, 2),
            number_format((float) $run->pool_amount, 2),
            $run->eligible_count,
            $run->eligible_count === 1 ? '' : 's',
            number_format((float) $run->equal_share, 2),
        );
    }

    /**
     * Confirm one Company Club payment.
     */
    public function markPaid(Request $request, RewardLedger $reward): RedirectResponse
    {
        if ($reward->reward_type !== RewardType::CompanyClub) {
            return back()->with('error', 'That reward is not a Company Club reward.');
        }

        try {
            $this->payments->pay($reward, $request->user());
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->syncRewardStatus($reward->period);

        return back()->with('success', sprintf(
            'Company Club reward of %s confirmed as paid.',
            number_format((float) $reward->amount, 2),
        ));
    }

    /**
     * Confirm every outstanding Company Club payment in a period.
     */
    public function markAllPaid(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        try {
            $count = $this->payments->payAll(
                $validated['period'],
                RewardType::CompanyClub,
                $request->user(),
            );
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->syncRewardStatus($validated['period']);

        return back()->with('success', sprintf(
            '%d Company Club reward%s confirmed as paid. %s is now locked against recalculation.',
            $count,
            $count === 1 ? '' : 's',
            $validated['period'],
        ));
    }

    /**
     * Mirror the ledger's paid status onto the module's own reward rows.
     *
     * The ledger stays the single source of truth for payment; this keeps the
     * Company Club screens from having to join it on every read.
     */
    private function syncRewardStatus(string $period): void
    {
        $run = $this->club->latestRun($period);

        if ($run === null) {
            return;
        }

        $paidMemberIds = RewardLedger::query()
            ->ofType(RewardType::CompanyClub)
            ->forPeriod($period)
            ->paid()
            ->pluck('member_id');

        if ($paidMemberIds->isEmpty()) {
            return;
        }

        $run->rewards()
            ->whereIn('member_id', $paidMemberIds)
            ->update(['status' => LedgerStatus::Paid->value]);
    }
}

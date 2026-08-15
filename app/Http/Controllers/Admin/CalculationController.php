<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CalculationRunType;
use App\Enums\RewardType;
use App\Http\Controllers\Controller;
use App\Models\CalculationRun;
use App\Models\Member;
use App\Models\RewardLedger;
use App\Models\TeamCalculation;
use App\Models\UplineCalculation;
use App\Services\CalculationRunService;
use App\Services\DirectRewardService;
use App\Services\TeamSalesService;
use App\Services\UplineRewardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * Calculation Center.
 *
 * PHASE 5 SCOPE: only "Calculate Direct" is wired. The remaining engines are
 * rendered as disabled controls labelled with the phase that delivers them, and
 * "Calculate All" arrives with the full Calculation Center in Phase 12.
 */
class CalculationController extends Controller
{
    public function __construct(
        private readonly DirectRewardService $direct,
        private readonly UplineRewardService $upline,
        private readonly TeamSalesService $team,
        private readonly CalculationRunService $runs,
    ) {}

    public function index(Request $request): View
    {
        $period = $request->query('period', now()->format('Y-m'));

        $preview = null;
        $uplinePreview = null;
        $error = null;

        try {
            $preview = $this->direct->preview($period);
            $uplinePreview = $this->upline->preview($period);
            $teamPreview = $this->team->preview($period);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        return view('admin.calculations.index', [
            'period' => $period,
            'preview' => $preview,
            'uplinePreview' => $uplinePreview,
            'teamPreview' => $teamPreview ?? null,
            'previewError' => $error,
            'directRun' => $this->runs->completedRun($period, CalculationRunType::Direct),
            'uplineRun' => $this->runs->completedRun($period, CalculationRunType::Upline),
            'teamRun' => $this->runs->completedRun($period, CalculationRunType::TeamSales),
            'runs' => CalculationRun::with('initiatedBy:id,name')
                ->latest('id')
                ->limit(10)
                ->get(),
        ]);
    }

    public function direct(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        try {
            $run = $this->direct->calculate($validated['period'], $request->user());
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.calculations.show', $run)
            ->with('success', sprintf(
                'Direct reward calculated for %s: %s entries, %s Sq.Ft., ₹%s.',
                $run->period,
                number_format($run->records_created),
                number_format((float) $run->total_sqft, 2),
                number_format((float) $run->total_amount, 2),
            ));
    }

    public function uplineRun(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        try {
            $run = $this->upline->calculate($validated['period'], $request->user());
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.calculations.show', $run)
            ->with('success', sprintf(
                'Upline reward calculated for %s: %s shares, ₹%s distributed.',
                $run->period,
                number_format($run->records_created),
                number_format((float) $run->total_amount, 2),
            ));
    }

    /**
     * Upline ledger for a period, with the working behind each share.
     */
    public function uplineLedger(Request $request): View
    {
        $period = $request->query('period', now()->format('Y-m'));

        return view('admin.calculations.upline', [
            'period' => $period,
            'rows' => UplineCalculation::query()
                ->forPeriod($period)
                ->with(['seller:id,member_code,name', 'receiver:id,member_code,name'])
                ->orderBy('seller_id')
                ->orderBy('upline_level')
                ->paginate(config('members.per_page'))
                ->withQueryString(),
            'receivers' => RewardLedger::query()
                ->ofType(RewardType::Upline)
                ->forPeriod($period)
                ->with('member:id,member_code,name')
                ->selectRaw('member_id, COUNT(*) as entries, SUM(amount) as amount')
                ->groupBy('member_id')
                ->orderByDesc('amount')
                ->limit(10)
                ->get(),
            'totals' => RewardLedger::query()
                ->ofType(RewardType::Upline)
                ->forPeriod($period)
                ->selectRaw('COUNT(*) as entries, COALESCE(SUM(amount),0) as amount')
                ->first(),
        ]);
    }

    public function teamSalesRun(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        try {
            $run = $this->team->calculate($validated['period'], $request->user());
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.calculations.team.report', ['period' => $run->period])
            ->with('success', sprintf(
                'Team sales calculated for %s: %s leaders rolled up.',
                $run->period,
                number_format($run->records_created),
            ));
    }

    /**
     * Team sales report — every leader's own + downline totals for a period.
     */
    public function teamReport(Request $request): View
    {
        $period = $request->query('period', now()->format('Y-m'));

        return view('admin.calculations.team', [
            'period' => $period,
            'rows' => TeamCalculation::query()
                ->forPeriod($period)
                ->with('leader:id,member_code,name,sponsor_id,status')
                ->orderByDesc('total_team_sqft')
                ->paginate(config('members.per_page'))
                ->withQueryString(),
            'companySqft' => TeamCalculation::query()
                ->forPeriod($period)
                ->sum('own_sqft'),
        ]);
    }

    /**
     * Which members' sales rolled up into one leader's team total.
     */
    public function teamContributors(Request $request, Member $member): View
    {
        $period = $request->query('period', now()->format('Y-m'));

        return view('admin.calculations.team-contributors', [
            'member' => $member,
            'period' => $period,
            'contributors' => $this->team->contributors($member, $period),
            'calculation' => TeamCalculation::query()
                ->forPeriod($period)
                ->where('leader_id', $member->id)
                ->first(),
        ]);
    }

    /**
     * Upline Explorer — the whole rule for one member, in one page.
     *
     * Shows the path from the root down, the annotated chain above the member
     * (who qualifies and why), what their own sales paid out, and what they
     * received from their downline.
     */
    public function uplineExplain(Request $request, Member $member): View
    {
        $period = $request->query('period', now()->format('Y-m'));

        return view('admin.calculations.upline-explain', [
            'member' => $member,
            'period' => $period,
            'path' => $this->upline->pathFromRoot($member),
            'chain' => $this->upline->annotatedChain($member),
            'distribution' => $this->upline->distributionBySeller($member, $period),
            'receipts' => $this->upline->receiptsFor($member, $period),
            'periods' => UplineCalculation::query()
                ->select('period')
                ->distinct()
                ->orderByDesc('period')
                ->pluck('period'),
        ]);
    }

    public function show(CalculationRun $run): View
    {
        return view('admin.calculations.show', [
            'run' => $run->load('initiatedBy:id,name'),
            'entries' => $run->entries()
                ->with(['member:id,member_code,name'])
                ->orderBy('member_id')
                ->paginate(config('members.per_page')),
        ]);
    }

    /**
     * Direct reward ledger for a period, grouped by member.
     */
    public function directLedger(Request $request): View
    {
        $period = $request->query('period', now()->format('Y-m'));

        $rows = RewardLedger::query()
            ->ofType(RewardType::Direct)
            ->forPeriod($period)
            ->with('member:id,member_code,name')
            ->selectRaw('member_id, COUNT(*) as entries, SUM(sqft) as sqft, SUM(amount) as amount')
            ->groupBy('member_id')
            ->orderByDesc('amount')
            ->paginate(config('members.per_page'))
            ->withQueryString();

        $totals = RewardLedger::query()
            ->ofType(RewardType::Direct)
            ->forPeriod($period)
            ->selectRaw('COUNT(*) as entries, COALESCE(SUM(sqft),0) as sqft, COALESCE(SUM(amount),0) as amount')
            ->first();

        return view('admin.calculations.direct', [
            'period' => $period,
            'rows' => $rows,
            'totals' => $totals,
        ]);
    }
}

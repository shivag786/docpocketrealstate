<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RewardType;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\RewardLedger;
use App\Models\TeamCalculation;
use App\Models\UplineCalculation;
use App\Services\TeamSalesService;
use App\Services\UplineRewardService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Reward reports — who earned what, and the working behind it.
 *
 * These are read-only operator pages and they are deliberately NOT part of the
 * Calculation Center. The Calculation Center answers "have the engines run and
 * are the figures level with the sales"; these pages answer "what did they
 * produce". They used to live under /admin/calculations/* because they were
 * written into CalculationController for convenience, which put a reward report
 * inside the machine room and lit up the wrong sidebar entry. They now sit under
 * /admin/rewards/* alongside the Direct Sale report, and the old URLs redirect.
 */
class RewardReportController extends Controller
{
    public function __construct(
        private readonly UplineRewardService $upline,
        private readonly TeamSalesService $team,
    ) {}

    /**
     * Direct reward ledger for a period, grouped by member.
     *
     * Distinct from the Direct Sale report next door: that one works the reward
     * out from the sales themselves and is therefore honest for an uncalculated
     * month; this one reads what the engine actually wrote to the ledger.
     */
    public function directLedger(Request $request): View
    {
        $period = $request->query('period', now()->format('Y-m'));

        return view('admin.rewards.direct-ledger', [
            'period' => $period,
            'rows' => RewardLedger::query()
                ->ofType(RewardType::Direct)
                ->forPeriod($period)
                ->with('member:id,member_code,name')
                ->selectRaw('member_id, COUNT(*) as entries, SUM(sqft) as sqft, SUM(amount) as amount')
                ->groupBy('member_id')
                ->orderByDesc('amount')
                ->paginate(config('members.per_page'))
                ->withQueryString(),
            'totals' => RewardLedger::query()
                ->ofType(RewardType::Direct)
                ->forPeriod($period)
                ->selectRaw('COUNT(*) as entries, COALESCE(SUM(sqft),0) as sqft, COALESCE(SUM(amount),0) as amount')
                ->first(),
        ]);
    }

    /**
     * Upline ledger for a period, with the working behind each share.
     *
     * Hidden since 2026-08-27. The route stays registered and the page stays
     * written: the engine still runs, so this is a screen switched off rather
     * than a feature removed, and flipping the config brings it back whole.
     */
    public function uplineLedger(Request $request): View
    {
        $this->assertUplineIsVisible();

        $period = $request->query('period', now()->format('Y-m'));

        return view('admin.rewards.upline', [
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

    /**
     * Upline Explorer — the whole rule for one member, in one page.
     *
     * Shows the path from the root down, the annotated chain above the member
     * (who qualifies and why), what their own sales paid out, and what they
     * received from their downline.
     */
    public function uplineExplain(Request $request, Member $member): View
    {
        $this->assertUplineIsVisible();

        $period = $request->query('period', now()->format('Y-m'));

        return view('admin.rewards.upline-explain', [
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

    /**
     * Team sales report — every leader's own + downline totals for a period.
     *
     * Pays nobody. It is the measurement the Target engine is judged on, which
     * is why it is reported beside the rewards rather than hidden.
     */
    public function teamSales(Request $request): View
    {
        $period = $request->query('period', now()->format('Y-m'));

        return view('admin.rewards.team-sales', [
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

        return view('admin.rewards.team-contributors', [
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
     * Refuse the two Upline screens while the engine is hidden.
     *
     * 404 rather than 403: to an operator the page does not exist. Nothing
     * links here any more, so reaching it means an old bookmark.
     */
    private function assertUplineIsVisible(): void
    {
        abort_unless(RewardType::Upline->isVisible(), 404);
    }
}

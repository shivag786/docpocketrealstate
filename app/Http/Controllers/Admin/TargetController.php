<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CalculationRunType;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\TargetCalculation;
use App\Services\CalculationRunService;
use App\Services\TargetRewardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * One Month Target (Target 1).
 *
 * Two report pages over the same period, as requested by the client: who
 * achieved the target, and who did not. They are the same query with the verdict
 * flipped, so they share a partial and differ only in the filter and the empty
 * state.
 *
 * Clicking any member opens the explanation page — their team drawn as a tree,
 * with the Sq.Ft. each member contributed in the period.
 */
class TargetController extends Controller
{
    public function __construct(
        private readonly TargetRewardService $targets,
        private readonly CalculationRunService $runs,
    ) {}

    /** Members who reached the target and are owed the reward. */
    public function achieved(Request $request): View
    {
        return view('admin.targets.index', $this->page($request, achieved: true));
    }

    /** Members who were measured and fell short. They retry next month. */
    public function missed(Request $request): View
    {
        return view('admin.targets.index', $this->page($request, achieved: false));
    }

    /**
     * Shared data for both pages.
     *
     * @return array<string, mixed>
     */
    private function page(Request $request, bool $achieved): array
    {
        $period = $this->period($request);

        $rows = TargetCalculation::query()
            ->forPeriod($period)
            ->atLevel(TargetRewardService::LEVEL)
            ->when($achieved, fn ($q) => $q->achieved(), fn ($q) => $q->missed())
            ->with('member:id,member_code,name,sponsor_id,status')
            ->orderByDesc('achieved_sqft')
            ->paginate(config('members.per_page'))
            ->withQueryString();

        // Both counts on both pages, so the tab badges are always truthful and
        // the operator can see at a glance what the other page holds.
        $counts = TargetCalculation::query()
            ->forPeriod($period)
            ->atLevel(TargetRewardService::LEVEL)
            ->selectRaw('COUNT(*) as measured')
            ->selectRaw('COALESCE(SUM(achieved), 0) as achieved')
            ->selectRaw('COALESCE(SUM(reward_amount), 0) as amount')
            ->first();

        return [
            'period' => $period,
            'showingAchieved' => $achieved,
            'rows' => $rows,
            'measured' => (int) $counts->measured,
            'achievedCount' => (int) $counts->achieved,
            'missedCount' => (int) $counts->measured - (int) $counts->achieved,
            'totalAmount' => (string) $counts->amount,
            'targetSqft' => $this->targets->targetSqft(),
            'rewardAmount' => $this->targets->rewardAmount(),
            'run' => $this->runs->completedRun($period, CalculationRunType::Target),
            'periods' => TargetCalculation::query()
                ->select('period')
                ->distinct()
                ->orderByDesc('period')
                ->pluck('period'),
        ];
    }

    /**
     * One member's target verdict, explained by their team tree.
     */
    public function show(Request $request, Member $member): View
    {
        $period = $this->period($request);

        return view('admin.targets.show', [
            'member' => $member,
            'period' => $period,
            'calculation' => TargetCalculation::query()
                ->forPeriod($period)
                ->atLevel(TargetRewardService::LEVEL)
                ->where('member_id', $member->id)
                ->first(),
            'tree' => $this->targets->contributionTree($member, $period),
            'targetSqft' => $this->targets->targetSqft(),
            'rewardAmount' => $this->targets->rewardAmount(),
            'history' => $this->targets->forMember($member),
        ]);
    }

    /**
     * Run the Target 1 calculation for a period.
     */
    public function run(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        try {
            $run = $this->targets->calculate($validated['period'], $request->user());
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.targets.achieved', ['period' => $run->period])
            ->with('success', sprintf(
                'Target 1 calculated for %s: %s members measured, ₹%s awarded.',
                $run->period,
                number_format($run->records_created),
                number_format((float) $run->total_amount, 2),
            ));
    }

    private function period(Request $request): string
    {
        $period = (string) $request->query('period', now()->format('Y-m'));

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1
            ? $period
            : now()->format('Y-m');
    }
}

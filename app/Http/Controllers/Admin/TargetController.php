<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CalculationRunType;
use App\Enums\RewardType;
use App\Enums\TargetLevel;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\RewardLedger;
use App\Models\TargetCalculation;
use App\Services\CalculationRunService;
use App\Services\PeriodRecalculationService;
use App\Services\RewardPaymentService;
use App\Services\TargetRewardService;
use App\Support\Export\TableExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * The three team targets.
 *
 * Two report pages over the same period and the same target level, as requested
 * by the client: who reached it, and who did not. They are the same query with
 * the verdict flipped, so they share a view and differ only in the filter and
 * the empty state. A `level` parameter switches between Target 1, 2 and 3 — the
 * pages are otherwise identical, because the rules only differ in threshold and
 * window length.
 *
 * "Not reached" on a multi-month target covers two different situations, and the
 * page distinguishes them: a window that closed short, and a window still open
 * with months left to run. Collapsing them would tell an operator someone had
 * failed when they are two weeks into an attempt.
 *
 * Clicking any member opens the explanation page — their team drawn as a tree,
 * with the Sq.Ft. each member contributed, plus the month-by-month build-up of
 * the window when the target spans more than one month.
 */
class TargetController extends Controller
{
    public function __construct(
        private readonly TargetRewardService $targets,
        private readonly RewardPaymentService $payments,
        private readonly PeriodRecalculationService $recalculations,
        private readonly CalculationRunService $runs,
    ) {}

    /**
     * Confirm that one member's target reward has been paid.
     *
     * This is the point a provisional figure becomes final: it freezes the
     * amount and locks the whole month against further recalculation.
     */
    public function markPaid(Request $request, RewardLedger $reward): RedirectResponse
    {
        if ($reward->reward_type !== RewardType::Target) {
            abort(404);
        }

        try {
            $this->payments->pay($reward, $request->user());
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', sprintf(
            'Marked paid: ₹%s to %s for %s. %s is now locked and will not recalculate.',
            number_format((float) $reward->amount, 2),
            $reward->member->member_code,
            $reward->period,
            $reward->period,
        ));
    }

    /**
     * Confirm every unpaid target reward in a period at once.
     *
     * Deliberately period-wide rather than per level: the control sits on a
     * month, and paying "everything owed for August" is the operation an
     * operator actually performs.
     */
    public function markAllPaid(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        try {
            $count = $this->payments->payAll($validated['period'], RewardType::Target, $request->user());
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($count === 0) {
            return back()->with('error', 'There was nothing left to pay for this month.');
        }

        return back()->with('success', sprintf(
            '%d target reward%s marked paid for %s. The month is now locked.',
            $count,
            $count === 1 ? '' : 's',
            $validated['period'],
        ));
    }

    /**
     * Rebuild every engine for a period on demand.
     *
     * Recalculation is automatic on sale entry; this exists for months that were
     * calculated before that existed, or that an operator wants to force.
     */
    public function recalculate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        try {
            $this->recalculations->recalculate($validated['period'], $request->user());
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', sprintf(
            'All figures for %s recalculated from the current sales, and every later month re-judged.',
            $validated['period'],
        ));
    }

    /** A hard ceiling on one download — an export is built in memory. */
    private const EXPORT_LIMIT = 5000;

    /** Members who reached the target and are owed the reward. */
    public function achieved(Request $request): View
    {
        return view('admin.targets.index', $this->page($request, achieved: true));
    }

    /** Members who have not reached it — window closed short, or still open. */
    public function missed(Request $request): View
    {
        return view('admin.targets.index', $this->page($request, achieved: false));
    }

    /**
     * Either list, as a file: one / two / three month target, achieved or not.
     *
     * The month, the target and which of the two lists it is all travel with
     * the download — in the filename and again inside the file. A target sheet
     * that does not say which month or which target it covers is unusable the
     * moment it leaves the screen.
     */
    public function export(Request $request, string $format): StreamedResponse|Response
    {
        if (! TableExport::supports($format)) {
            throw new NotFoundHttpException('Unknown export format.');
        }

        $period = $this->period($request);
        $level = $this->level($request);
        $achieved = $request->boolean('achieved', true);

        $rows = TargetCalculation::query()
            ->forPeriod($period)
            ->atLevel($level)
            ->when($achieved, fn ($q) => $q->achieved(), fn ($q) => $q->missed())
            ->with('member:id,member_code,name,mobile')
            ->orderByDesc('cumulative_sqft')
            ->limit(self::EXPORT_LIMIT)
            ->get()
            ->map(fn (TargetCalculation $row) => [
                $row->member?->member_code ?? '',
                $row->member?->name ?? '',
                $row->member?->mobile ?? '',
                $level->label(),
                $row->windowLabel(),
                number_format((float) $row->cumulative_sqft, 2, '.', ''),
                number_format((float) $row->target_sqft, 2, '.', ''),
                number_format((float) $row->shortfall_sqft, 2, '.', ''),
                $row->outcome()->label(),
                number_format((float) $row->reward_amount, 2, '.', ''),
            ])
            ->all();

        $list = $achieved ? 'Achieved' : 'Not reached';

        return TableExport::make(
            title: $level->label().' — '.$list,
            subtitle: TableExport::context($period, [
                'Threshold' => number_format((float) $level->sqft(), 0).' Sq.Ft. over '
                    .$level->months().' month'.($level->months() === 1 ? '' : 's'),
                'Winning prize' => '₹'.number_format((float) $level->reward(), 2),
                'Members' => count($rows),
            ]),
            headings: [
                'Member code', 'Member name', 'Mobile', 'Target', 'Window',
                'Team Sq.Ft.', 'Threshold', 'Shortfall', 'Outcome', 'Prize',
            ],
            rows: $rows,
            weights: [1.0, 1.7, 1.1, 1.3, 1.2, 1.0, 1.0, 1.0, 1.1, 1.0],
        )->download($format, TableExport::filename(
            'target-'.$level->value.'-month',
            $period,
            $achieved ? 'achieved' : 'not-reached',
        ));
    }

    /**
     * Shared data for both pages.
     *
     * @return array<string, mixed>
     */
    private function page(Request $request, bool $achieved): array
    {
        $period = $this->period($request);
        $level = $this->level($request);

        $rows = TargetCalculation::query()
            ->forPeriod($period)
            ->atLevel($level)
            ->when($achieved, fn ($q) => $q->achieved(), fn ($q) => $q->missed())
            ->with('member:id,member_code,name,sponsor_id,status')
            ->orderByDesc('cumulative_sqft')
            ->paginate(config('members.per_page'))
            ->withQueryString();

        // Every count on both pages, so the tab badges are always truthful and
        // the operator can see at a glance what the other page holds.
        //
        // `toBase()`, and an alias that is NOT a column name, are both load
        // bearing. An aggregate row is not a model, and hydrating it as one puts
        // the sum through the model's casts: `achieved` is cast to boolean, so
        // `SUM(achieved) as achieved` came back as `true`, and `(int) true` is 1.
        // Two achievers reported as one — the list showed both while the tile
        // above it said 1. Aggregates here stay raw.
        $counts = TargetCalculation::query()
            ->forPeriod($period)
            ->atLevel($level)
            ->toBase()
            ->selectRaw('COUNT(*) as measured')
            ->selectRaw('COALESCE(SUM(achieved), 0) as achieved_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN achieved = 0 AND period < window_end THEN 1 ELSE 0 END), 0) as in_progress')
            ->selectRaw('COALESCE(SUM(reward_amount), 0) as amount')
            ->first();

        $measured = (int) $counts->measured;
        $achievedCount = (int) $counts->achieved_total;
        $inProgress = (int) $counts->in_progress;

        // The ledger row behind each verdict on this page, so the list can show
        // paid state and offer the control without a query per row.
        $rewards = RewardLedger::query()
            ->ofType(RewardType::Target)
            ->forPeriod($period)
            ->whereIn('source_id', $rows->pluck('id'))
            ->where('source_type', 'target_calculation')
            ->with('paidBy:id,name')
            ->get()
            ->keyBy('source_id');

        return [
            'period' => $period,
            'level' => $level,
            'levels' => TargetLevel::all(),
            'levelCounts' => $this->levelCounts($period),
            'showingAchieved' => $achieved,
            'rows' => $rows,
            'rewards' => $rewards,
            'measured' => $measured,
            'achievedCount' => $achievedCount,
            'inProgressCount' => $inProgress,
            'missedCount' => $measured - $achievedCount - $inProgress,
            'totalAmount' => (string) $counts->amount,
            'targetSqft' => $level->sqft(),
            'rewardAmount' => $level->reward(),
            'run' => $this->runs->completedRun($period, CalculationRunType::Target),
            // Payment state for the whole month, across all three targets.
            'payment' => $this->payments->summary($period, RewardType::Target),
            'payable' => $this->payments->periodIsPayable($period),
            'paymentBlockedReason' => $this->payments->blockedReason($period),
            'periodLocked' => $this->runs->periodIsPaid($period),
            'periods' => TargetCalculation::query()
                ->select('period')
                ->distinct()
                ->orderByDesc('period')
                ->pluck('period'),
        ];
    }

    /**
     * How many members each target is measuring this period.
     *
     * Drives the level switcher, so a level with nothing in it says so rather
     * than looking like a broken page when opened.
     *
     * @return array<int, int>
     */
    private function levelCounts(string $period): array
    {
        $counts = TargetCalculation::query()
            ->forPeriod($period)
            ->selectRaw('target_level, COUNT(*) as total')
            ->groupBy('target_level')
            ->pluck('total', 'target_level');

        $out = [];

        foreach (TargetLevel::all() as $level) {
            $out[$level->value] = (int) ($counts[$level->value] ?? 0);
        }

        return $out;
    }

    /**
     * One member's target verdict, explained.
     */
    public function show(Request $request, Member $member): View
    {
        $period = $this->period($request);

        // The member's own level in this period, not whatever level the page
        // was opened from — a member is measured against exactly one target at
        // a time and that is the one worth explaining.
        $calculation = TargetCalculation::query()
            ->forPeriod($period)
            ->where('member_id', $member->id)
            ->first();

        return view('admin.targets.show', [
            'member' => $member,
            'period' => $period,
            'calculation' => $calculation,
            'level' => $calculation?->target_level ?? $this->level($request),
            'windowMonths' => $calculation ? $this->targets->windowMonths($calculation) : [],
            'tree' => $this->targets->contributionTree($member, $period),
            'history' => $this->targets->forMember($member),
        ]);
    }

    /**
     * Run the target calculation for a period.
     *
     * One run covers all three targets: every member is measured against
     * whichever one they are currently on, so splitting the run by level would
     * mean three runs that each had to know about the other two.
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
                'Targets calculated for %s: %s members measured, ₹%s awarded.',
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

    private function level(Request $request): TargetLevel
    {
        return TargetLevel::tryFrom((int) $request->query('level', 1)) ?? TargetLevel::One;
    }
}

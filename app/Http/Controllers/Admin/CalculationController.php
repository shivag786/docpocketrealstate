<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CalculationRunType;
use App\Enums\RewardType;
use App\Enums\TargetLevel;
use App\Http\Controllers\Controller;
use App\Models\CalculationRun;
use App\Services\CalculationRunService;
use App\Services\DirectRewardService;
use App\Services\PeriodRecalculationService;
use App\Services\RewardPaymentService;
use App\Services\TargetRewardService;
use App\Services\TeamSalesService;
use App\Services\UplineRewardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * Calculation Center — the machine room, not a report.
 *
 * It used to be the place an operator came to PRESS the four buttons that
 * produced a month's figures. That stopped being true on 2026-08-17: entering a
 * sale now rebuilds every engine for its month automatically
 * (`PeriodRecalculationService`). By the time anyone opens this page the work
 * has already happened, so a page of "Calculate X" buttons was describing a
 * workflow that no longer exists.
 *
 * What it answers now is the one question automation cannot answer for itself:
 * ARE THIS MONTH'S FIGURES STILL LEVEL WITH ITS SALES? Each engine is shown
 * twice — worked out from the sales as they stand right now, beside what its
 * last run actually stored — so a disagreement is visible rather than inferred.
 * Rebuilding by hand remains available for the two cases automation cannot
 * cover: a month that was locked by a payment, and a month calculated before
 * automatic recalculation existed.
 *
 * The reward REPORTS that used to live here now sit under /admin/rewards/* in
 * `RewardReportController`, where the sidebar, the breadcrumb and the URL agree
 * about what they are.
 */
class CalculationController extends Controller
{
    public function __construct(
        private readonly DirectRewardService $direct,
        private readonly UplineRewardService $upline,
        private readonly TeamSalesService $team,
        private readonly TargetRewardService $targets,
        private readonly CalculationRunService $runs,
        private readonly PeriodRecalculationService $recalculation,
        private readonly RewardPaymentService $payments,
    ) {}

    public function index(Request $request): View
    {
        $period = $request->query('period', now()->format('Y-m'));

        $engines = [];
        $error = null;
        $status = null;

        try {
            $engines = $this->engines($period);
            $status = $this->recalculation->periodStatus($period);
        } catch (Throwable $e) {
            // A malformed or future period. The page still renders: the run
            // history and the drift list do not depend on the chosen period.
            $error = $e->getMessage();
        }

        return view('admin.calculations.index', [
            'period' => $period,
            'engines' => $engines,
            'previewError' => $error,
            'status' => $status,
            'monthIsOver' => $this->payments->periodIsPayable($period),
            'stale' => $this->recalculation->stalePeriods(),
            // A hidden engine's runs are omitted here too, so that
            // "hidden" means one thing everywhere: RECONCILIATION IS THE ONLY
            // SCREEN THAT SHOWS A HIDDEN ENGINE. The runs still happen and are
            // still recorded — nothing is deleted — they are simply not listed
            // beside engines the operator has a report for.
            'runs' => CalculationRun::with('initiatedBy:id,name')
                ->whereNotIn('run_type', $this->hiddenRunTypes())
                ->latest('id')
                ->limit(10)
                ->get(),
            'hiddenEngines' => array_map(
                fn (RewardType $type) => $type->label(),
                array_values(array_filter(RewardType::cases(), fn (RewardType $t) => ! $t->isVisible())),
            ),
        ]);
    }

    /**
     * Run types belonging to an engine the operator cannot see.
     *
     * @return list<string>
     */
    private function hiddenRunTypes(): array
    {
        return array_values(array_map(
            fn (CalculationRunType $type) => $type->value,
            array_filter(
                CalculationRunType::cases(),
                fn (CalculationRunType $type) => $type->rewardType()?->isVisible() === false,
            ),
        ));
    }

    /**
     * Every engine, shown as "from the sales now" beside "what the run stored".
     *
     * The pair per engine is chosen to be directly comparable, which is why the
     * unit differs: Direct is compared on money, Team Sales on Sq.Ft. because it
     * pays nobody, and Upline on the DISTRIBUTED total rather than the pool —
     * shares are rounded individually, so the pool legitimately differs from
     * what is paid out and comparing against it would report a fault that is not
     * one.
     *
     * TARGETS ARE DELIBERATELY NOT COMPARED (`comparable => false`). A target
     * pays once per member ever, so a member who won in this month has moved on
     * to the next target — a fresh preview of a month that had an achiever
     * therefore reports that win differently from the run that recorded it.
     * Flagging that as a mismatch would raise an alarm on every month that ever
     * produced a winner. What the verdicts actually depend on is the Team Sales
     * figure above, which IS compared.
     *
     * @return array<int, array<string, mixed>>
     */
    private function engines(string $period): array
    {
        $direct = $this->direct->preview($period);
        $upline = $this->upline->preview($period);
        $team = $this->team->preview($period);
        $target = $this->targets->preview($period);

        // A hidden engine still RUNS - Rebuild covers all four in dependency
        // order exactly as before - but it gets no card, because a card exists
        // to be read against a report the operator can open. Upline is hidden
        // as of 2026-08-27; see config/rewards.php `visibility`.
        return array_values(array_filter([
            [
                'label' => 'Direct',
                'rule' => 'Own approved Sq.Ft. × ₹'.config('rewards.rates.direct').', one entry per sale.',
                'run' => $this->runs->completedRun($period, CalculationRunType::Direct),
                'unit' => 'money',
                'comparable' => true,
                'live' => $direct['amount'],
                'live_count' => $direct['sales'],
                'count_label' => 'sales',
                'report' => route('admin.rewards.direct-ledger', ['period' => $period]),
                'report_label' => 'Direct reward ledger',
                'note' => null,
            ],
            RewardType::Upline->isVisible() ? [
                'label' => 'Upline',
                'rule' => sprintf(
                    "Seller's monthly Sq.Ft. × ₹%s, divided equally among up to %d active uplines.",
                    config('rewards.rates.upline'),
                    config('rewards.upline.max_levels'),
                ),
                'run' => $this->runs->completedRun($period, CalculationRunType::Upline),
                'unit' => 'money',
                'comparable' => true,
                'live' => $upline['distributed'],
                'live_count' => $upline['receipts'],
                'count_label' => 'shares',
                'report' => route('admin.rewards.upline', ['period' => $period]),
                'report_label' => 'Upline reward ledger',
                'note' => (float) $upline['residual'] !== 0.0
                    ? sprintf(
                        'Shares are rounded off individually, so what is distributed differs from the ₹%s pool by ₹%s.',
                        number_format((float) $upline['pool'], 2),
                        number_format((float) $upline['residual'], 2),
                    )
                    : null,
            ] : null,
            [
                'label' => 'Team Sales',
                'rule' => "Each leader's own Sq.Ft. plus every connected downline's, at any depth. Pays nobody — it is the figure the targets are judged on.",
                'run' => $this->runs->completedRun($period, CalculationRunType::TeamSales),
                'unit' => 'sqft',
                'comparable' => true,
                'live' => $team['company_sqft'],
                'live_count' => $team['leaders'],
                'count_label' => 'leaders',
                'report' => route('admin.rewards.team-sales', ['period' => $period]),
                'report_label' => 'Team sales report',
                'note' => null,
            ],
            [
                'label' => 'Team Targets',
                'rule' => sprintf(
                    "Team Sq.Ft. accumulated inside each member's current window: %s. "
                    .'Paid at the threshold, once per target per member, then the next target opens.',
                    implode(', ', array_map(
                        fn (TargetLevel $level) => sprintf(
                            '%s over %d month%s',
                            number_format((float) $level->sqft(), 0),
                            $level->months(),
                            $level->months() === 1 ? '' : 's',
                        ),
                        TargetLevel::all(),
                    )),
                ),
                'run' => $this->runs->completedRun($period, CalculationRunType::Target),
                'unit' => 'money',
                'comparable' => false,
                'live' => null,
                'live_count' => $target['achieved'],
                'count_label' => 'achievers',
                'report' => route('admin.targets.achieved', ['period' => $period]),
                'report_label' => 'Target results',
                'note' => $target['team_sales_ready']
                    ? sprintf(
                        'Right now %s %s being measured this month: %s short and %s still inside an open window. '
                        .'A target pays once per member ever, so a re-run cannot reproduce a past win — the verdict '
                        .'beside this is what was actually recorded. It was judged on the Team Sales figure above.',
                        number_format($target['measured']),
                        $target['measured'] === 1 ? 'member is' : 'members are',
                        number_format($target['missed']),
                        number_format($target['in_progress']),
                    )
                    : 'Judged on the Team Sales runs above, which have not been calculated for every month with sales up to here.',
            ],
        ]));
    }

    /**
     * Rebuild every engine for one period, in dependency order.
     *
     * One button rather than four, because the order is not optional: Target is
     * judged on the figures Team Sales produces. Four separate buttons let an
     * operator run them in an order that silently tests this month's targets
     * against last month's rollup.
     */
    public function rebuild(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        try {
            $completed = $this->recalculation->recalculate($validated['period'], $request->user());
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        $status = $this->recalculation->periodStatus($validated['period']);

        return redirect()
            ->route('admin.calculations.index', ['period' => $validated['period']])
            ->with('success', sprintf(
                '%s rebuilt: %d engines re-run against %s Sq.Ft. of approved sales.',
                $validated['period'],
                count($completed),
                number_format((float) $status['live_sqft'], 2),
            ));
    }

    public function direct(Request $request): RedirectResponse
    {
        return $this->runOne($request, CalculationRunType::Direct);
    }

    public function uplineRun(Request $request): RedirectResponse
    {
        return $this->runOne($request, CalculationRunType::Upline);
    }

    public function teamSalesRun(Request $request): RedirectResponse
    {
        return $this->runOne($request, CalculationRunType::TeamSales);
    }

    /**
     * Run a single engine for a period.
     *
     * Kept, but deliberately demoted in the UI behind "Rebuild this month":
     * running one engine on its own is how a month ends up internally
     * inconsistent.
     */
    private function runOne(Request $request, CalculationRunType $type): RedirectResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        try {
            $run = match ($type) {
                CalculationRunType::Direct => $this->direct->calculate($validated['period'], $request->user()),
                CalculationRunType::Upline => $this->upline->calculate($validated['period'], $request->user()),
                CalculationRunType::TeamSales => $this->team->calculate($validated['period'], $request->user()),
                default => throw new RuntimeException("No engine wired for {$type->value}."),
            };
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.calculations.show', $run)
            ->with('success', sprintf(
                '%s calculated for %s: %s entries, %s Sq.Ft., ₹%s.',
                $run->run_type->label(),
                $run->period,
                number_format($run->records_created),
                number_format((float) $run->total_sqft, 2),
                number_format((float) $run->total_amount, 2),
            ));
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
}

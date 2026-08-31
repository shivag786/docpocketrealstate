<?php

namespace App\Services;

use App\Enums\CalculationRunType;
use App\Models\CalculationRun;
use App\Models\RegistrySale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Keeps a month's figures level with its sales.
 *
 * Client-confirmed 2026-08-17: "everytime all calculation will be count of each
 * sale of every day, until month end." Entering a sale therefore recalculates
 * the month it belongs to, across every engine, immediately. Nothing waits for
 * an operator to remember to press a button.
 *
 * ORDER IS NOT ARBITRARY. Target is judged on the figures Team Sales produces,
 * so Team Sales must be rebuilt before Target or the targets would be tested
 * against the previous month's rollup. Direct and Upline are independent of
 * both and of each other.
 *
 * THE ONE THING THAT STOPS IT: a period holding any PAID reward is locked and
 * refuses to recalculate. That is what protects disbursed money from being
 * silently rewritten by a late sale.
 */
class PeriodRecalculationService
{
    /**
     * Engines in dependency order. Team Sales before Target, always.
     *
     * Company Club is NOT in this list. It is rebuilt separately, after these
     * four, and only for a month an admin has already calculated once - see
     * `recalculate()`.
     */
    private const ORDER = [
        CalculationRunType::Direct,
        CalculationRunType::Upline,
        CalculationRunType::TeamSales,
        CalculationRunType::Target,
    ];

    public function __construct(
        private readonly DirectRewardService $direct,
        private readonly UplineRewardService $upline,
        private readonly TeamSalesService $team,
        private readonly TargetRewardService $targets,
        private readonly CompanyClubService $companyClub,
        private readonly CalculationRunService $runs,
    ) {}

    /**
     * Rebuild every engine for one period.
     *
     * All four run inside ONE transaction. A month is a single consistent
     * picture: it must never be left with a fresh Direct total beside a stale
     * Target verdict because the fourth engine failed.
     *
     * @return array<string, CalculationRun> keyed by run type value
     *
     * @throws RuntimeException when the period is locked by a paid reward
     */
    public function recalculate(string $period, User $initiatedBy): array
    {
        $this->runs->assertValidPeriod($period);
        $this->runs->assertPeriodNotPaid($period);

        // Targets 2 and 3 accumulate across months, so a change here moves every
        // later verdict too. Those months must be rebuildable before anything is
        // written, or the rebuild would leave the ladder half-updated.
        $laterPeriods = $this->periodsAfter($period);

        foreach ($laterPeriods as $later) {
            $this->runs->assertPeriodNotPaid($later);
        }

        return DB::transaction(function () use ($period, $initiatedBy, $laterPeriods) {
            $completed = [];

            foreach (self::ORDER as $type) {
                $completed[$type->value] = match ($type) {
                    CalculationRunType::Direct => $this->direct->recalculate($period, $initiatedBy),
                    CalculationRunType::Upline => $this->upline->recalculate($period, $initiatedBy),
                    CalculationRunType::TeamSales => $this->team->recalculate($period, $initiatedBy),
                    CalculationRunType::Target => $this->targets->recalculate($period, $initiatedBy),
                    default => throw new RuntimeException("No engine wired for {$type->value}."),
                };
            }

            // Only Target cascades. Direct, Upline and Team Sales each describe
            // one month and nothing else, so a later month's figures for them
            // are untouched by a change here.
            foreach ($laterPeriods as $later) {
                $completed['target:'.$later] = $this->targets->recalculate($later, $initiatedBy);
            }

            /*
             * Company Club runs LAST, and only when the month already has a
             * completed run.
             *
             * Client-confirmed 2026-08-19: recalculation should keep the figures
             * current. But the FIRST calculation of a month has to stay an
             * admin's explicit decision - previewed, then confirmed - so a month
             * nobody has calculated is deliberately left untouched here and
             * `recalculateIfCalculated()` returns null for it.
             *
             * It runs inside the same transaction as the other four on purpose.
             * A month is one consistent picture; a Company Club failure that left
             * fresh Direct figures beside stale Company Club ones would be worse
             * than the whole rebuild failing and being reported.
             *
             * Company Club does NOT cascade into later periods. Its pool is one
             * month's sales and nothing else - unlike Target, which accumulates
             * across a window.
             */
            $club = $this->companyClub->recalculateIfCalculated($period, $initiatedBy);

            if ($club !== null) {
                $completed[CalculationRunType::CompanyClub->value] = $club->calculationRun;
            }

            return $completed;
        });
    }

    /**
     * Months after `$period` whose target verdicts depend on it.
     *
     * A month qualifies if it has ever been rolled up or judged — those are
     * exactly the months holding stored figures that the change could falsify.
     *
     * @return array<int, string>
     */
    public function periodsAfter(string $period): array
    {
        $fromTeam = DB::table('team_calculations')->where('period', '>', $period)->distinct()->pluck('period');
        $fromTargets = DB::table('target_calculations')->where('period', '>', $period)->distinct()->pluck('period');

        $periods = $fromTeam->merge($fromTargets)->unique()->sort()->values()->all();

        return array_map('strval', $periods);
    }

    /**
     * Recalculate after a sale was recorded, without ever losing the sale.
     *
     * The sale is the fact; the figures are derived from it. If recalculation
     * cannot run — most likely because the month is locked by a paid reward —
     * the sale still stands and the reason is returned for the operator to see.
     * Swallowing it silently would leave figures quietly wrong, which is the
     * exact failure this whole mechanism exists to remove.
     *
     * @return array{recalculated: bool, reason: ?string}
     */
    public function afterSale(RegistrySale $sale, User $initiatedBy): array
    {
        $period = $sale->registry_date->format('Y-m');

        try {
            $this->recalculate($period, $initiatedBy);

            return ['recalculated' => true, 'reason' => null];
        } catch (Throwable $e) {
            // Logged as well as returned: a month drifting out of step with its
            // sales is worth a trace, not just a flash message.
            Log::warning('Recalculation after sale entry failed.', [
                'sale_id' => $sale->id,
                'period' => $period,
                'reason' => $e->getMessage(),
            ]);

            return ['recalculated' => false, 'reason' => $e->getMessage()];
        }
    }

    /**
     * Whether one period's stored figures still match its sales.
     *
     * Judged on Sq.Ft. against the DIRECT run, because Direct is the only engine
     * whose stored total is the plain sum of the period's approved sales. Upline
     * divides through the network, Team Sales counts the same Sq.Ft. once per
     * leader in the chain, and Target stores the threshold it paid on — a
     * difference in any of those would not tell you whether a sale went missing.
     *
     * @return array{period: string, live_sqft: string, run_sqft: string, calculated: bool, in_step: bool, locked: bool}
     */
    public function periodStatus(string $period): array
    {
        $liveSqft = (string) (RegistrySale::query()
            ->approved()
            ->forPeriod($period)
            ->sum('sqft') ?: '0.00');

        $run = $this->runs->completedRun($period, CalculationRunType::Direct);
        $runSqft = (string) ($run?->total_sqft ?? '0.00');

        return [
            'period' => $period,
            'live_sqft' => $liveSqft,
            'run_sqft' => $runSqft,
            'calculated' => $run !== null,
            'in_step' => bccomp($liveSqft, $runSqft, 2) === 0,
            'locked' => $this->runs->periodIsPaid($period),
        ];
    }

    /**
     * Periods whose stored figures no longer match their sales.
     *
     * Recalculation is automatic, so this should normally be empty. It will not
     * be for months that were locked by a payment, or that predate automatic
     * recalculation — which is exactly when an operator needs telling.
     *
     * @return array<int, array{period: string, live_sqft: string, run_sqft: string, calculated: bool, in_step: bool, locked: bool}>
     */
    public function stalePeriods(): array
    {
        $periods = RegistrySale::query()
            ->approved()
            ->selectRaw("DATE_FORMAT(registry_date, '%Y-%m') as period")
            ->groupBy('period')
            ->orderByDesc('period')
            ->pluck('period');

        return array_values(array_filter(
            array_map(fn (string $period) => $this->periodStatus($period), $periods->all()),
            fn (array $status) => ! $status['in_step'],
        ));
    }
}

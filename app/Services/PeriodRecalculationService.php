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

        return DB::transaction(function () use ($period, $initiatedBy) {
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

            return $completed;
        });
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
     * Periods whose stored figures no longer match their sales.
     *
     * Recalculation is automatic, so this should normally be empty. It will not
     * be for months that were locked by a payment, or that predate automatic
     * recalculation — which is exactly when an operator needs telling.
     *
     * @return array<int, array{period: string, live_sqft: string, run_sqft: string, locked: bool}>
     */
    public function stalePeriods(): array
    {
        $stale = [];

        $periods = RegistrySale::query()
            ->approved()
            ->selectRaw("DATE_FORMAT(registry_date, '%Y-%m') as period")
            ->selectRaw('COALESCE(SUM(sqft), 0) as live_sqft')
            ->groupBy('period')
            ->orderByDesc('period')
            ->get();

        foreach ($periods as $row) {
            $run = $this->runs->completedRun($row->period, CalculationRunType::Direct);
            $runSqft = $run?->total_sqft ?? '0.00';

            if (bccomp((string) $row->live_sqft, (string) $runSqft, 2) !== 0) {
                $stale[] = [
                    'period' => $row->period,
                    'live_sqft' => (string) $row->live_sqft,
                    'run_sqft' => (string) $runSqft,
                    'locked' => $this->runs->periodIsPaid($row->period),
                ];
            }
        }

        return $stale;
    }
}

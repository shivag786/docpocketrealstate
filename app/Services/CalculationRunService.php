<?php

namespace App\Services;

use App\Enums\CalculationRunStatus;
use App\Enums\CalculationRunType;
use App\Enums\LedgerStatus;
use App\Models\CalculationRun;
use App\Models\RewardLedger;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Lifecycle of a calculation run.
 *
 * Two modes, and the difference is the whole model:
 *
 *  - `execute()` is FIRST-TIME calculation. A completed run blocks a second one.
 *  - `replace()` is RECALCULATION. It deletes the previous results, marks the
 *    previous runs superseded and calculates again from the current sales.
 *
 * Client-confirmed 2026-08-17: figures are recalculated every time a sale is
 * entered and stay provisional for the whole month, so `replace()` is the normal
 * path and a month accumulates many runs. What makes a figure final is an admin
 * confirming PAYMENT — and a paid period can no longer be recalculated at all,
 * which is the one thing protecting disbursed money from being rewritten.
 */
class CalculationRunService
{
    /**
     * Execute an engine inside a run, in a single transaction.
     *
     * The callback receives the run and returns a summary. If it throws, the
     * whole transaction rolls back — no partial ledger — and the failure is
     * recorded against a separate run row so the attempt is still visible.
     *
     * @param  callable(CalculationRun): array{records: int, sqft: string, amount: string}  $engine
     * @param  null|callable(string): void  $clear  Deletes the engine's own prior
     *         rows for the period. Supplying it switches this call to
     *         recalculation: instead of refusing a second run, the previous
     *         results are replaced.
     */
    public function execute(
        string $period,
        CalculationRunType $type,
        User $initiatedBy,
        callable $engine,
        ?callable $clear = null,
    ): CalculationRun {
        $this->assertValidPeriod($period);

        if ($clear === null) {
            $this->assertNotAlreadyCalculated($period, $type);
        } else {
            $this->assertPeriodNotPaid($period);
        }

        try {
            return DB::transaction(function () use ($period, $type, $initiatedBy, $engine, $clear) {
                // Re-check inside the transaction: two operators pressing the
                // button at the same moment must not both proceed. The unique
                // index on reward_ledger is the final backstop if they do.
                if ($clear === null) {
                    $this->assertNotAlreadyCalculated($period, $type);
                } else {
                    $this->assertPeriodNotPaid($period);
                    $this->discardPreviousResults($period, $type, $clear);
                }

                $run = CalculationRun::create([
                    'period' => $period,
                    'run_type' => $type,
                    'status' => CalculationRunStatus::Running,
                    'started_at' => now(),
                    'initiated_by' => $initiatedBy->id,
                ]);

                $summary = $engine($run);

                $run->update([
                    'status' => CalculationRunStatus::Completed,
                    'completed_at' => now(),
                    'records_created' => $summary['records'],
                    'total_sqft' => $summary['sqft'],
                    'total_amount' => $summary['amount'],
                ]);

                return $run->refresh();
            });
        } catch (Throwable $e) {
            // Recorded outside the rolled-back transaction so the failure survives.
            CalculationRun::create([
                'period' => $period,
                'run_type' => $type,
                'status' => CalculationRunStatus::Failed,
                'started_at' => now(),
                'completed_at' => now(),
                'initiated_by' => $initiatedBy->id,
                'error_message' => $e->getMessage(),
                'records_created' => 0,
                'total_sqft' => Money::zero(),
                'total_amount' => Money::zero(),
            ]);

            throw $e;
        }
    }

    /**
     * Throw away what a previous run of this period and type produced.
     *
     * Order matters: the ledger rows and the engine's own rows both point at
     * the old runs by foreign key, so they must go before the runs are touched.
     * The runs themselves are NEVER deleted — they record who calculated what
     * and when, which is the only history recalculation would otherwise erase.
     *
     * @param  callable(string): void  $clear
     */
    private function discardPreviousResults(string $period, CalculationRunType $type, callable $clear): void
    {
        $rewardType = $type->rewardType();

        if ($rewardType !== null) {
            RewardLedger::query()
                ->where('period', $period)
                ->where('reward_type', $rewardType)
                ->delete();
        }

        // The engine deletes its own calculation table; only it knows which.
        $clear($period);

        CalculationRun::for($period, $type)
            ->where('status', CalculationRunStatus::Completed)
            ->update([
                'status' => CalculationRunStatus::Superseded,
                'superseded_at' => now(),
            ]);
    }

    /**
     * Recalculation is refused once ANY reward in the period has been paid.
     *
     * Deliberately period-wide rather than per reward type. The four engines
     * describe one month between them: re-running Team Sales after a Target
     * reward was paid would silently move the ground the payment stood on. One
     * confirmed payment freezes the whole month, which is a rule an operator can
     * hold in their head.
     */
    public function assertPeriodNotPaid(string $period): void
    {
        $paid = RewardLedger::query()
            ->where('period', $period)
            ->where('status', LedgerStatus::Paid)
            ->count();

        if ($paid > 0) {
            throw new RuntimeException(sprintf(
                '%s is locked: %d reward%s in this month %s already been marked paid. '
                .'Figures can no longer be recalculated, because that would rewrite an '
                .'amount somebody has been paid.',
                $period,
                $paid,
                $paid === 1 ? '' : 's',
                $paid === 1 ? 'has' : 'have',
            ));
        }
    }

    public function periodIsPaid(string $period): bool
    {
        return RewardLedger::query()
            ->where('period', $period)
            ->where('status', LedgerStatus::Paid)
            ->exists();
    }

    /**
     * A period must be a real calendar month, and never in the future — rewards
     * cannot be calculated for a month that has not happened.
     */
    public function assertValidPeriod(string $period): void
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw new InvalidArgumentException("Invalid period [{$period}]. Expected format YYYY-MM.");
        }

        if ($period > now()->format('Y-m')) {
            throw new InvalidArgumentException("Period [{$period}] is in the future.");
        }
    }

    /**
     * docs/05_CALCULATION_ENGINE_SPEC.md §G: the same run must not duplicate
     * ledger rows. Recalculation has to be explicit and controlled, which is a
     * Phase 12 concern; until then a completed run simply blocks a second one.
     */
    public function assertNotAlreadyCalculated(string $period, CalculationRunType $type): void
    {
        $existing = CalculationRun::for($period, $type)->completed()->first();

        if ($existing !== null) {
            throw new RuntimeException(sprintf(
                '%s has already been calculated for %s (run #%d on %s). Recalculation is not yet available.',
                $type->label(),
                $period,
                $existing->id,
                $existing->completed_at?->format('d M Y, H:i') ?? '—',
            ));
        }
    }

    public function completedRun(string $period, CalculationRunType $type): ?CalculationRun
    {
        return CalculationRun::for($period, $type)->completed()->latest('id')->first();
    }
}

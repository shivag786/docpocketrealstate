<?php

namespace App\Services;

use App\Enums\CalculationRunStatus;
use App\Enums\CalculationRunType;
use App\Enums\LedgerStatus;
use App\Enums\RewardType;
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
 * confirming PAYMENT - and a paid engine can no longer be recalculated at all,
 * which is the one thing protecting disbursed money from being rewritten.
 *
 * THAT LOCK IS PER ENGINE, NOT PER MONTH (client-confirmed 2026-09-01). Paying a
 * Company Club share freezes Company Club and nothing else; paying a Team Target
 * freezes Target and the Team Sales rollup its verdict is read off. The rule
 * lives on CalculationRunType::lockedBy(), not here.
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
            $this->assertPeriodNotPaid($period, $type);
        }

        try {
            return DB::transaction(function () use ($period, $type, $initiatedBy, $engine, $clear) {
                // Re-check inside the transaction: two operators pressing the
                // button at the same moment must not both proceed. The unique
                // index on reward_ledger is the final backstop if they do.
                if ($clear === null) {
                    $this->assertNotAlreadyCalculated($period, $type);
                } else {
                    $this->assertPeriodNotPaid($period, $type);
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
     * Recalculation is refused once THIS ENGINE'S reward has been paid.
     *
     * Client-confirmed 2026-09-01, and a deliberate narrowing of what used to be
     * a period-wide freeze. Company Club money and Team Target money are
     * approved separately and disbursed separately, so confirming one must not
     * freeze the other: a paid Club share is no reason to refuse to rebuild a
     * Target verdict nobody has been paid for yet.
     *
     * What each engine is frozen by is declared once, on the run type - see
     * CalculationRunType::lockedBy(). Team Sales is locked by Target rather than
     * by itself, because Target's verdict is read off its rollup.
     *
     * The protection is unchanged in the only respect that matters: an amount
     * somebody has been paid can still never be rewritten.
     */
    public function assertPeriodNotPaid(string $period, CalculationRunType $type): void
    {
        $paid = $this->paidRewardCounts($period, $type);

        if ($paid === []) {
            return;
        }

        $parts = [];

        foreach ($paid as $label => $count) {
            $parts[] = sprintf('%d %s reward%s', $count, $label, $count === 1 ? '' : 's');
        }

        throw new RuntimeException(sprintf(
            '%s is locked for %s: %s in this month %s already been marked paid. '
            .'These figures can no longer be recalculated, because that would rewrite an '
            .'amount somebody has been paid. Every other reward in %s is unaffected.',
            $period,
            $type->label(),
            implode(' and ', $parts),
            array_sum($paid) === 1 ? 'has' : 'have',
            $period,
        ));
    }

    /**
     * Whether one engine's figures are frozen by a confirmed payment.
     *
     * Always asked about a specific engine. There is no period-wide answer any
     * more, because there is no period-wide lock - `anyRewardPaid()` exists for
     * the few screens that genuinely mean "has any money left this month".
     */
    public function periodIsPaid(string $period, CalculationRunType $type): bool
    {
        return $this->paidRewardCounts($period, $type) !== [];
    }

    /**
     * Whether any reward at all in the period has been paid.
     *
     * NOT A LOCK. Nothing may refuse to recalculate on the strength of this -
     * use `assertPeriodNotPaid()` with the engine being rebuilt. This answers
     * only "has money gone out of this month", which is a reporting question.
     */
    public function anyRewardPaid(string $period): bool
    {
        return RewardLedger::query()
            ->where('period', $period)
            ->where('status', LedgerStatus::Paid)
            ->exists();
    }

    /**
     * Paid reward counts that bear on one engine, keyed by reward-type label.
     *
     * Empty when nothing that could freeze this engine has been paid.
     *
     * @return array<string, int>
     */
    private function paidRewardCounts(string $period, CalculationRunType $type): array
    {
        $blocking = array_map(fn (RewardType $reward) => $reward->value, $type->lockedBy());

        $counts = RewardLedger::query()
            ->where('period', $period)
            ->where('status', LedgerStatus::Paid)
            ->whereIn('reward_type', $blocking)
            ->selectRaw('reward_type, COUNT(*) as total')
            ->groupBy('reward_type')
            ->pluck('total', 'reward_type');

        $out = [];

        foreach ($counts as $rewardType => $total) {
            $label = ($rewardType instanceof RewardType ? $rewardType : RewardType::from($rewardType))->label();
            $out[$label] = (int) $total;
        }

        return $out;
    }

    /**
     * Whether the calendar month has finished.
     *
     * The one definition of "the month is over", shared by the Company Club
     * calculation gate and by RewardPaymentService. A month still running keeps
     * producing sales, so anything needing completed input asks this.
     */
    public function periodHasEnded(string $period): bool
    {
        return $period < now()->format('Y-m');
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

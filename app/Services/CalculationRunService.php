<?php

namespace App\Services;

use App\Enums\CalculationRunStatus;
use App\Enums\CalculationRunType;
use App\Models\CalculationRun;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Lifecycle of a calculation run.
 *
 * PHASE 5 SCOPE: this is the minimum needed for the Direct engine to be safe —
 * period validation, duplicate protection, transactional execution and failure
 * recording. Phase 12 builds the Calculation Center on top of it (Calculate All,
 * run history UI, controlled recalculation) without changing this contract.
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
     */
    public function execute(
        string $period,
        CalculationRunType $type,
        User $initiatedBy,
        callable $engine,
    ): CalculationRun {
        $this->assertValidPeriod($period);
        $this->assertNotAlreadyCalculated($period, $type);

        try {
            return DB::transaction(function () use ($period, $type, $initiatedBy, $engine) {
                // Re-check inside the transaction: two operators pressing the
                // button at the same moment must not both proceed. The unique
                // index on reward_ledger is the final backstop if they do.
                $this->assertNotAlreadyCalculated($period, $type);

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

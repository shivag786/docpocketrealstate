<?php

namespace App\Services;

use App\Enums\CalculationRunType;
use App\Enums\LedgerStatus;
use App\Enums\RewardType;
use App\Models\CalculationRun;
use App\Models\Member;
use App\Models\RegistrySale;
use App\Models\RewardLedger;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Direct Sale reward engine.
 *
 *      direct_amount = own approved sale Sq.Ft. × ₹40
 *
 * Client-confirmed rules this engine obeys (docs/02_BUSINESS_RULES.md §1):
 *
 *  - It reads ONLY the seller's own approved sales. Downline sales are
 *    irrelevant here; they belong to the Team Target engine.
 *  - Target achievement has NO effect. A member who misses every target still
 *    receives the full direct reward on every approved sale. Nothing in this
 *    class may ever consult a target, and a test asserts that.
 *  - It shares no arithmetic with the Upline, Target or Company Club engines.
 *
 * One ledger row is written per approved sale rather than one per member, so
 * every rupee traces back to a specific registry — which is what makes Phase 13
 * reconciliation possible.
 */
class DirectRewardService
{
    public function __construct(
        private readonly CalculationRunService $runs,
    ) {}

    /**
     * Calculate and post direct rewards for a period.
     */
    public function calculate(string $period, User $initiatedBy): CalculationRun
    {
        return $this->runs->execute(
            $period,
            CalculationRunType::Direct,
            $initiatedBy,
            fn (CalculationRun $run) => $this->post($period, $run),
        );
    }

    /**
     * Recalculate the period from the sales as they stand now.
     *
     * The previous run's ledger rows are deleted and rewritten. Direct rewards
     * live entirely in `reward_ledger`, which `CalculationRunService` clears for
     * us, so this engine has nothing of its own to discard.
     */
    public function recalculate(string $period, User $initiatedBy): CalculationRun
    {
        return $this->runs->execute(
            $period,
            CalculationRunType::Direct,
            $initiatedBy,
            fn (CalculationRun $run) => $this->post($period, $run),
            fn (string $p) => null,
        );
    }

    /**
     * @return array{records: int, sqft: string, amount: string}
     */
    private function post(string $period, CalculationRun $run): array
    {
        $rate = RewardType::Direct->rate();

        $records = 0;
        $totalSqft = Money::zero();
        $totalAmount = Money::zero();
        $rows = [];

        // Chunked: a busy month could hold a large number of sales, and the
        // whole set must never be held in memory at once.
        $this->eligibleSales($period)->chunkById(500, function (Collection $sales) use (
            $rate, $run, $period, &$records, &$totalSqft, &$totalAmount, &$rows
        ) {
            foreach ($sales as $sale) {
                // sqft is a decimal string from the database; it never becomes
                // a float on the way to the ledger.
                $sqft = Money::of($sale->sqft);
                $amount = Money::multiply($sqft, $rate);

                $rows[] = [
                    'member_id' => $sale->member_id,
                    'reward_type' => RewardType::Direct->value,
                    'source_type' => 'registry_sale',
                    'source_id' => $sale->id,
                    'period' => $period,
                    'sqft' => $sqft,
                    // The rate is frozen onto the row so this run stays
                    // reproducible even if the configured rate later changes.
                    'rate' => $rate,
                    'amount' => $amount,
                    'status' => LedgerStatus::Posted->value,
                    'calculation_run_id' => $run->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $records++;
                $totalSqft = Money::add($totalSqft, $sqft);
                $totalAmount = Money::add($totalAmount, $amount);
            }

            if ($rows !== []) {
                RewardLedger::insert($rows);
                $rows = [];
            }
        });

        return [
            'records' => $records,
            'sqft' => $totalSqft,
            'amount' => $totalAmount,
        ];
    }

    /**
     * The sales a direct reward is owed on.
     *
     * Routed through RegistrySale's own scopes so "approved" and "period" stay
     * defined in exactly one place for every engine.
     *
     * @return \Illuminate\Database\Eloquent\Builder<RegistrySale>
     */
    private function eligibleSales(string $period)
    {
        return RegistrySale::query()
            ->approved()
            ->forPeriod($period)
            ->select(['id', 'member_id', 'sqft']);
    }

    // -----------------------------------------------------------------
    // Read-side helpers (no calculation, no side effects)
    // -----------------------------------------------------------------

    /**
     * What a period WOULD produce, without writing anything.
     *
     * Lets an operator see the figures before committing them.
     *
     * @return array{sales: int, members: int, sqft: string, amount: string}
     */
    public function preview(string $period): array
    {
        $this->runs->assertValidPeriod($period);

        $sales = RegistrySale::query()
            ->approved()
            ->forPeriod($period)
            ->get(['member_id', 'sqft']);

        $sqft = Money::sum($sales->pluck('sqft'));

        return [
            'sales' => $sales->count(),
            'members' => $sales->pluck('member_id')->unique()->count(),
            'sqft' => $sqft,
            'amount' => Money::multiply($sqft, RewardType::Direct->rate()),
        ];
    }

    /**
     * A member's direct rewards, newest period first.
     *
     * @return Collection<int, object>
     */
    public function forMember(Member $member): Collection
    {
        return RewardLedger::query()
            ->ofType(RewardType::Direct)
            ->where('member_id', $member->id)
            ->selectRaw('period, COUNT(*) as entries, SUM(sqft) as sqft, SUM(amount) as amount')
            ->groupBy('period')
            ->orderByDesc('period')
            ->get();
    }
}

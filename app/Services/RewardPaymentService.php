<?php

namespace App\Services;

use App\Enums\LedgerStatus;
use App\Enums\RewardType;
use App\Models\RewardLedger;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Confirming that a reward has actually been paid.
 *
 * Client-confirmed 2026-08-17: a reward shows as soon as it is earned, mid-month
 * and provisional, but the Mark Paid control stays disabled by default. Only when
 * an admin presses it and confirms does the status become paid.
 *
 * This is the point where a figure stops being provisional. Everything before it
 * is recalculated freely as sales arrive; everything after it is frozen — and
 * ONLY that reward type freezes with it (client-confirmed 2026-09-01). Paying a
 * Company Club share does not stop a Team Target being rebuilt, and the reverse
 * holds too: they are separate money with separate approval, and the lock they
 * each carry is their own. See CalculationRunType::lockedBy().
 *
 * A CUT-OFF SITS BETWEEN MONTH END AND PAYMENT (client-confirmed 2026-09-01).
 * The month ending is not the same as every sale in it having been entered —
 * registry paperwork arrives days late, and a sale keyed in after payment lands
 * against a locked engine and can never be absorbed. Those few days are what
 * turn "a late sale is lost" into "a late sale still counts", and they cost
 * nothing: nobody was being paid on the 1st anyway.
 */
class RewardPaymentService
{
    public function __construct(
        private readonly CalculationRunService $runs,
    ) {}

    /**
     * Whether a period's rewards may be paid yet.
     *
     * Two gates, and they are not the same gate:
     *
     *  1. The month must have ENDED. A month still in progress keeps changing —
     *     "until month end" — so paying from it would confirm a figure that has
     *     not finished moving.
     *  2. The cut-off must have PASSED. The last days of a month are still being
     *     keyed in on the first days of the next one, and a sale entered after
     *     payment hits a locked engine and is lost for good. The cut-off is the
     *     window that lets that paperwork land while the figures can still
     *     absorb it.
     */
    public function periodIsPayable(string $period): bool
    {
        if (! $this->runs->periodHasEnded($period)) {
            return false;
        }

        return now()->startOfDay()->greaterThanOrEqualTo($this->payableFrom($period));
    }

    /**
     * The first day a period's rewards may be confirmed as paid.
     *
     * Month end plus the cut-off. A cut-off of 0 means the 1st of the next
     * month, which is the pre-2026-09-01 behaviour and still configurable.
     */
    public function payableFrom(string $period): CarbonImmutable
    {
        return CarbonImmutable::parse($period.'-01')
            ->addMonth()
            ->startOfDay()
            ->addDays($this->cutoffDays());
    }

    /**
     * How many days after month end payment opens.
     *
     * Clamped at zero: a negative cut-off would let a month be paid before it
     * had ended, which gate 1 forbids anyway, and reading as if it might is
     * worse than refusing the setting.
     */
    public function cutoffDays(): int
    {
        return max(0, (int) config('rewards.payment_cutoff_days', 0));
    }

    /**
     * Why the Mark Paid control is disabled, or null when it is available.
     */
    public function blockedReason(string $period): ?string
    {
        if ($this->periodIsPayable($period)) {
            return null;
        }

        $from = $this->payableFrom($period)->format('d M Y');

        if (! $this->runs->periodHasEnded($period)) {
            return sprintf(
                '%s has not finished. Figures keep changing as sales are entered, '
                .'so rewards can be paid from %s.',
                $period,
                $from,
            );
        }

        return sprintf(
            '%s has ended, but its %s-day entry window is still open until %s. '
            .'Sales from the last days of the month are often keyed in late, and '
            .'one entered after payment can no longer be absorbed — the figures '
            .'would be frozen against it.',
            $period,
            $this->cutoffDays(),
            $from,
        );
    }

    /**
     * Mark one reward as paid.
     *
     * @throws RuntimeException when the month is not over, or it is already paid
     */
    public function pay(RewardLedger $reward, User $confirmedBy): RewardLedger
    {
        if (! $this->periodIsPayable($reward->period)) {
            throw new RuntimeException($this->blockedReason($reward->period));
        }

        if ($reward->status === LedgerStatus::Paid) {
            throw new RuntimeException(sprintf(
                'This reward was already marked paid on %s.',
                $reward->paid_at?->format('d M Y, H:i') ?? 'an earlier date',
            ));
        }

        return DB::transaction(function () use ($reward, $confirmedBy) {
            // Re-read inside the transaction and lock the row: two operators
            // confirming the same payment must not both succeed.
            $fresh = RewardLedger::query()->lockForUpdate()->findOrFail($reward->id);

            if ($fresh->status === LedgerStatus::Paid) {
                throw new RuntimeException('This reward was already marked paid.');
            }

            $fresh->update([
                'status' => LedgerStatus::Paid,
                'paid_at' => now(),
                'paid_by' => $confirmedBy->id,
            ]);

            return $fresh->refresh();
        });
    }

    /**
     * Mark every unpaid reward of one type in a period as paid.
     *
     * @return int how many were confirmed
     */
    public function payAll(string $period, RewardType $type, User $confirmedBy): int
    {
        if (! $this->periodIsPayable($period)) {
            throw new RuntimeException($this->blockedReason($period));
        }

        return DB::transaction(fn () => RewardLedger::query()
            ->where('period', $period)
            ->where('reward_type', $type)
            ->where('status', LedgerStatus::Posted)
            ->update([
                'status' => LedgerStatus::Paid,
                'paid_at' => now(),
                'paid_by' => $confirmedBy->id,
                'updated_at' => now(),
            ]));
    }

    /**
     * Payment summary for one reward type in one period.
     *
     * @return array{total: int, paid: int, unpaid: int, paid_amount: string, unpaid_amount: string}
     */
    public function summary(string $period, RewardType $type): array
    {
        $rows = RewardLedger::query()
            ->where('period', $period)
            ->where('reward_type', $type)
            ->selectRaw('status, COUNT(*) as entries, COALESCE(SUM(amount), 0) as amount')
            ->groupBy('status')
            ->get()
            ->keyBy(fn ($row) => $row->status instanceof LedgerStatus ? $row->status->value : $row->status);

        $paid = $rows->get(LedgerStatus::Paid->value);
        $posted = $rows->get(LedgerStatus::Posted->value);

        return [
            'total' => (int) ($paid?->entries ?? 0) + (int) ($posted?->entries ?? 0),
            'paid' => (int) ($paid?->entries ?? 0),
            'unpaid' => (int) ($posted?->entries ?? 0),
            'paid_amount' => (string) ($paid?->amount ?? '0.00'),
            'unpaid_amount' => (string) ($posted?->amount ?? '0.00'),
        ];
    }
}

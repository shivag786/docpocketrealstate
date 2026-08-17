<?php

namespace App\Services;

use App\Enums\LedgerStatus;
use App\Enums\RewardType;
use App\Models\RewardLedger;
use App\Models\User;
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
 * is recalculated freely as sales arrive; everything after it is frozen, and the
 * whole period locks with it.
 */
class RewardPaymentService
{
    public function __construct(
        private readonly CalculationRunService $runs,
    ) {}

    /**
     * Whether a period's rewards may be paid yet.
     *
     * A month still in progress will keep changing — "until month end" — so
     * paying from it would be confirming a figure that has not finished moving.
     * The current month is therefore closed to payment until it ends.
     */
    public function periodIsPayable(string $period): bool
    {
        return $period < now()->format('Y-m');
    }

    /**
     * Why the Mark Paid control is disabled, or null when it is available.
     */
    public function blockedReason(string $period): ?string
    {
        if (! $this->periodIsPayable($period)) {
            return sprintf(
                '%s has not finished. Figures keep changing as sales are entered, '
                .'so rewards can be paid once the month is over.',
                $period,
            );
        }

        return null;
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

<?php

namespace App\Modules\MemberStatus\Adapters;

use App\Enums\LedgerStatus;
use App\Models\RewardLedger;
use App\Models\User;
use App\Modules\MemberStatus\Contracts\RewardGateway;
use App\Modules\MemberStatus\Data\RewardRow;
use App\Services\RewardPaymentService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * RewardGateway backed by the host application's `reward_ledger`.
 *
 * This is the ONE class in the module that touches an application model, and it
 * does so for a reason: confirming a payment must go through the application's
 * own RewardPaymentService, so the row locking, the "month must be over" rule
 * and the `paid_at` / `paid_by` audit fields stay identical to the existing
 * screens. Re-implementing payment here would create a second, divergent way to
 * spend money — the one thing this module must not do.
 *
 * Neither the service nor the model was modified to make this work. They are
 * called exactly as they already are elsewhere in the application.
 */
class EloquentRewardGateway implements RewardGateway
{
    public function __construct(
        private readonly RewardPaymentService $payments,
    ) {}

    /**
     * @return list<RewardRow>
     */
    public function rewardsFor(int|string $memberId): array
    {
        return RewardLedger::query()
            ->with('paidBy:id,name')
            ->where('member_id', $memberId)
            ->orderByDesc('period')
            ->orderBy('reward_type')
            ->orderByDesc('id')
            ->get()
            ->map(fn (RewardLedger $reward) => $this->toRow($reward))
            ->all();
    }

    public function find(int|string $rewardId): ?RewardRow
    {
        $reward = RewardLedger::query()->with('paidBy:id,name')->find($rewardId);

        return $reward === null ? null : $this->toRow($reward);
    }

    public function belongsToMember(int|string $rewardId, int|string $memberId): bool
    {
        return RewardLedger::query()
            ->whereKey($rewardId)
            ->where('member_id', $memberId)
            ->exists();
    }

    /**
     * @return array{total: int, paid: int, unpaid: int, paid_amount: string, unpaid_amount: string, total_amount: string}
     */
    public function summaryFor(int|string $memberId): array
    {
        $rows = RewardLedger::query()
            ->where('member_id', $memberId)
            ->selectRaw('status, COUNT(*) as entries, COALESCE(SUM(amount), 0) as amount')
            ->groupBy('status')
            ->get();

        $paidEntries = 0;
        $unpaidEntries = 0;
        $paidAmount = Money::zero();
        $unpaidAmount = Money::zero();

        foreach ($rows as $row) {
            $status = $row->status instanceof LedgerStatus ? $row->status->value : (string) $row->status;

            if ($status === LedgerStatus::Paid->value) {
                $paidEntries = (int) $row->entries;
                $paidAmount = Money::of($row->amount);

                continue;
            }

            $unpaidEntries = (int) $row->entries;
            $unpaidAmount = Money::of($row->amount);
        }

        return [
            'total' => $paidEntries + $unpaidEntries,
            'paid' => $paidEntries,
            'unpaid' => $unpaidEntries,
            'paid_amount' => $paidAmount,
            'unpaid_amount' => $unpaidAmount,
            'total_amount' => Money::add($paidAmount, $unpaidAmount),
        ];
    }

    /**
     * @param  list<int|string>  $memberIds
     * @return array<int|string, array{count: int, amount: string}>
     */
    public function unpaidSummaryForMany(array $memberIds): array
    {
        if ($memberIds === []) {
            return [];
        }

        return RewardLedger::query()
            ->whereIn('member_id', $memberIds)
            ->unpaid()
            ->selectRaw('member_id, COUNT(*) as entries, COALESCE(SUM(amount), 0) as amount')
            ->groupBy('member_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->member_id => [
                'count' => (int) $row->entries,
                'amount' => Money::of($row->amount),
            ]])
            ->all();
    }

    public function markPaid(int|string $rewardId, mixed $confirmedBy): RewardRow
    {
        $reward = RewardLedger::query()->find($rewardId);

        if ($reward === null) {
            throw new RuntimeException('That reward no longer exists.');
        }

        if (! $confirmedBy instanceof User) {
            throw new RuntimeException('A payment must be confirmed by a signed-in user.');
        }

        try {
            // The application's own service. Same locking, same period rule,
            // same audit trail as the existing Targets and Company Club pages.
            $paid = $this->payments->pay($reward, $confirmedBy);
        } catch (Throwable $e) {
            // Its messages are already written for an admin to read.
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        return $this->toRow($paid->load('paidBy:id,name'));
    }

    /**
     * @return list<int|string>
     */
    public function unpaidIdsFor(int|string $memberId): array
    {
        return RewardLedger::query()
            ->where('member_id', $memberId)
            ->unpaid()
            ->orderBy('period')
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    private function toRow(RewardLedger $reward): RewardRow
    {
        return new RewardRow(
            id: $reward->id,
            typeLabel: $reward->reward_type->label(),
            typeBadgeClass: $reward->reward_type->badgeClass(),
            period: (string) $reward->period,
            sqft: (string) $reward->sqft,
            rate: (string) $reward->rate,
            amount: (string) $reward->amount,
            paid: $reward->isPaid(),
            statusLabel: $reward->status->label(),
            statusBadgeClass: $reward->status->badgeClass(),
            paidAt: $reward->paid_at === null ? null : CarbonImmutable::parse($reward->paid_at),
            paidBy: $reward->paidBy?->name,
        );
    }
}

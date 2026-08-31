<?php

namespace App\Modules\MemberStatus\Repositories;

use App\Modules\MemberStatus\Enums\CalculatedStatus;
use App\Modules\MemberStatus\Models\MemberStatusHistory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The status-change audit trail (`member_status_history`).
 *
 * Append-only, and written ONLY on an actual transition. A daily job over a
 * network where nothing happened writes no rows at all, so the table reads as
 * the history of the business rather than the history of the scheduler.
 */
class StatusHistoryRepository
{
    public function record(
        int|string $memberId,
        ?CalculatedStatus $oldStatus,
        CalculatedStatus $newStatus,
        string $reason,
        CarbonImmutable $effectiveAt,
    ): MemberStatusHistory {
        return MemberStatusHistory::query()->create([
            'member_id' => $memberId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'reason' => $reason,
            'effective_at' => $effectiveAt->toDateString(),
            'created_at' => now(),
        ]);
    }

    /**
     * @return Collection<int, MemberStatusHistory>
     */
    public function forMember(int|string $memberId, int $limit = 50): Collection
    {
        return MemberStatusHistory::query()
            ->where('member_id', $memberId)
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, MemberStatusHistory>
     */
    public function recent(int $limit = 100): Collection
    {
        return MemberStatusHistory::query()
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}

<?php

namespace App\Modules\MemberStatus\Repositories;

use App\Modules\MemberStatus\Data\StatusResult;
use App\Modules\MemberStatus\Enums\CalculatedStatus;
use App\Modules\MemberStatus\Models\MemberStatusSnapshot;
use Carbon\CarbonImmutable;

/**
 * The module's own status value, one row per member (`member_status_snapshot`).
 *
 * `members.status` is never read or written here. That separation is the whole
 * point of the snapshot table (spec §21-§22).
 */
class StatusSnapshotRepository
{
    public function find(int|string $memberId): ?MemberStatusSnapshot
    {
        return MemberStatusSnapshot::query()->where('member_id', $memberId)->first();
    }

    /**
     * @param  list<int|string>  $memberIds
     * @return array<int|string, MemberStatusSnapshot>
     */
    public function findMany(array $memberIds): array
    {
        if ($memberIds === []) {
            return [];
        }

        return MemberStatusSnapshot::query()
            ->whereIn('member_id', $memberIds)
            ->get()
            ->keyBy('member_id')
            ->all();
    }

    public function statusOf(int|string $memberId): ?CalculatedStatus
    {
        return $this->find($memberId)?->status;
    }

    /**
     * Write the calculated status for a member.
     *
     * `status_changed_at` moves only when the status itself changes; a
     * recalculation that confirms the same status refreshes `calculated_at`
     * and the day count and leaves the change date alone. The report shows both
     * and they answer different questions.
     */
    public function store(
        int|string $memberId,
        StatusResult $result,
        CarbonImmutable $calculatedAt,
        bool $statusChanged,
    ): MemberStatusSnapshot {
        $existing = $this->find($memberId);

        $attributes = [
            'status' => $result->status,
            'last_activity_at' => $result->lastActivityAt?->toDateString(),
            'last_activity_type' => $result->activity?->type,
            'last_activity_source_member_id' => $result->activity?->sourceMemberId,
            'last_activity_sale_id' => $result->activity?->saleId,
            'reference_date' => $result->referenceDate->toDateString(),
            'days_since_activity' => $result->daysSinceActivity,
            'calculated_at' => $calculatedAt->toDateString(),
        ];

        if ($statusChanged || $existing === null) {
            $attributes['status_changed_at'] = $calculatedAt->toDateString();
        }

        if ($existing === null) {
            return MemberStatusSnapshot::query()->create([
                'member_id' => $memberId,
                ...$attributes,
            ]);
        }

        $existing->fill($attributes)->save();

        return $existing;
    }

    /**
     * How many members sit in each status right now.
     *
     * @return array<string, int>
     */
    public function statusTotals(): array
    {
        $totals = MemberStatusSnapshot::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $counts = [];

        foreach (CalculatedStatus::cases() as $case) {
            $counts[$case->value] = (int) ($totals[$case->value] ?? 0);
        }

        return $counts;
    }
}

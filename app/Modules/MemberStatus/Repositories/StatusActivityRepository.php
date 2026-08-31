<?php

namespace App\Modules\MemberStatus\Repositories;

use App\Modules\MemberStatus\Data\QualifyingActivity;
use App\Modules\MemberStatus\Enums\ActivityType;
use App\Modules\MemberStatus\Models\MemberStatusActivity;
use Carbon\CarbonImmutable;

/**
 * The qualifying-activity ledger (`member_status_activity`).
 *
 * WHAT THIS TABLE IS: a record of every qualifying event the module has
 * observed, so a status can be explained after the fact — "Shiva was active on
 * 20 Aug because A sold under registry 123".
 *
 * WHAT IT IS NOT: the source of truth. Status is always recalculated from the
 * PropertySaleProvider, which reads the real sales. If this ledger were the
 * input, a missed event would silently freeze a member's status forever.
 */
class StatusActivityRepository
{
    /**
     * Record an activity, ignoring one that has already been recorded.
     *
     * Idempotent by the (member, type, sale) unique key, which is what makes
     * the event listener safe to retry and the batch job safe to re-run.
     *
     * @return bool true when a new row was written
     */
    public function record(QualifyingActivity $activity): bool
    {
        if ($activity->saleId !== null && $this->exists($activity)) {
            return false;
        }

        MemberStatusActivity::query()->create([
            'member_id' => $activity->memberId,
            'activity_type' => $activity->type,
            'source_member_id' => $activity->sourceMemberId,
            'sale_id' => $activity->saleId,
            'activity_date' => $activity->activityDate->toDateString(),
        ]);

        return true;
    }

    /**
     * @param  iterable<QualifyingActivity>  $activities
     * @return int number of rows actually written
     */
    public function recordMany(iterable $activities): int
    {
        $written = 0;

        foreach ($activities as $activity) {
            $written += $this->record($activity) ? 1 : 0;
        }

        return $written;
    }

    public function exists(QualifyingActivity $activity): bool
    {
        return MemberStatusActivity::query()
            ->where('member_id', $activity->memberId)
            ->where('activity_type', $activity->type)
            ->where('sale_id', $activity->saleId)
            ->exists();
    }

    /**
     * The member's most recent recorded activity date of one kind.
     */
    public function latestDateOfType(int|string $memberId, ActivityType $type): ?CarbonImmutable
    {
        $date = MemberStatusActivity::query()
            ->where('member_id', $memberId)
            ->where('activity_type', $type)
            ->max('activity_date');

        return $date === null ? null : CarbonImmutable::parse($date)->startOfDay();
    }

    /**
     * How many activity rows a member has, by kind. Used by the report.
     *
     * @return array<string, int>
     */
    public function countsByType(int|string $memberId): array
    {
        return MemberStatusActivity::query()
            ->where('member_id', $memberId)
            ->selectRaw('activity_type, COUNT(*) as total')
            ->groupBy('activity_type')
            ->pluck('total', 'activity_type')
            ->map(fn ($total) => (int) $total)
            ->all();
    }
}

<?php

namespace App\Modules\MemberStatus\Services;

use App\Modules\MemberStatus\Data\MemberRecord;
use App\Modules\MemberStatus\Data\QualifyingActivity;
use App\Modules\MemberStatus\Data\StatusResult;
use App\Modules\MemberStatus\Enums\CalculatedStatus;
use App\Modules\MemberStatus\Support\Clock;
use App\Modules\MemberStatus\Support\StatusConfig;
use Carbon\CarbonImmutable;

/**
 * The status engine (spec §15-§16).
 *
 *      days since last qualifying activity  ->  ACTIVE | PENDING | INACTIVE
 *
 * A pure function with no database, no container and no side effects. Give it a
 * member, their latest qualifying activity and a date, and it returns the
 * status with the reasoning attached. Everything that persists or schedules
 * lives elsewhere, which is what makes the 90/180 rules provable without
 * touching the host application at all.
 *
 * Two rules are easy to get wrong and are handled explicitly here:
 *
 *  1. A member with NO activity is measured from their joining date, not from
 *     the beginning of time. A member who joined yesterday is ACTIVE (spec §10).
 *
 *  2. Any activity RESETS the clock. The engine only ever looks at the most
 *     recent qualifying activity, so an older one can never drag a member down
 *     (spec §8). The caller is responsible for handing over the latest one.
 */
class MemberStatusEngine
{
    public function __construct(
        private readonly StatusConfig $config,
    ) {}

    public function config(): StatusConfig
    {
        return $this->config;
    }

    /**
     * Decide a member's status.
     *
     * @param  QualifyingActivity|null  $activity  the member's MOST RECENT qualifying activity, null if they have never had any
     * @param  CalculatedStatus|null  $previousStatus  the status this module last calculated, null on first ever calculation
     * @param  CarbonImmutable|null  $asOf  the day to judge, defaults to today
     */
    public function calculate(
        MemberRecord $member,
        ?QualifyingActivity $activity = null,
        ?CalculatedStatus $previousStatus = null,
        ?CarbonImmutable $asOf = null,
    ): StatusResult {
        $asOf = Clock::at($asOf);

        // A member with no activity yet whose clock has been switched off never
        // decays. This is the escape hatch for a business that wants a member
        // to stay ACTIVE until they have had at least one sale to lose.
        if ($activity === null && ! $this->config->measureNewMembersFromJoiningDate) {
            return new StatusResult(
                status: CalculatedStatus::Active,
                lastActivityAt: null,
                referenceDate: $member->joinedAt,
                daysSinceActivity: 0,
                reason: 'No qualifying activity recorded; new members do not decay under the current configuration.',
                activity: null,
            );
        }

        $reference = $this->referenceDate($member, $activity);
        $days = Clock::daysBetween($reference, $asOf);

        $status = $this->statusForDays($days);

        // Reactivation gate. PENDING -> ACTIVE is always allowed; only the lift
        // out of INACTIVE is configurable (spec §17). This is the one place the
        // engine consults the previous status, and it can only ever hold a
        // member DOWN, never promote them.
        $blocked = $previousStatus === CalculatedStatus::Inactive
            && $status !== CalculatedStatus::Inactive
            && ! $this->config->allowInactiveReactivation;

        if ($blocked) {
            return new StatusResult(
                status: CalculatedStatus::Inactive,
                lastActivityAt: $activity?->activityDate,
                referenceDate: $reference,
                daysSinceActivity: $days,
                reason: 'Qualifying activity recorded, but reactivation from Inactive is disabled.',
                activity: $activity,
                reactivationBlocked: true,
            );
        }

        return new StatusResult(
            status: $status,
            lastActivityAt: $activity?->activityDate,
            referenceDate: $reference,
            daysSinceActivity: $days,
            reason: $this->reason($status, $days, $activity),
            activity: $activity,
        );
    }

    /**
     * The plain day-count rule, with no member context (spec §16).
     *
     * Public because it is the rule itself: the report, the tests and any
     * future caller that already knows the day count use this rather than
     * re-implementing the comparison.
     */
    public function statusForDays(int $days): CalculatedStatus
    {
        if ($days < $this->config->activePeriodDays) {
            return CalculatedStatus::Active;
        }

        if ($days < $this->config->inactiveThresholdDays()) {
            return CalculatedStatus::Pending;
        }

        return CalculatedStatus::Inactive;
    }

    /**
     * The date the inactivity clock counts from.
     *
     * The latest qualifying activity when there is one; otherwise the joining
     * date plus any configured grace. Never earlier than the joining date — a
     * member cannot have been inactive before they existed (spec §10).
     */
    private function referenceDate(MemberRecord $member, ?QualifyingActivity $activity): CarbonImmutable
    {
        $joined = $member->joinedAt->startOfDay()->addDays($this->config->newMemberGraceDays);

        if ($activity === null) {
            return $joined;
        }

        return $activity->activityDate->startOfDay()->max($member->joinedAt->startOfDay());
    }

    private function reason(CalculatedStatus $status, int $days, ?QualifyingActivity $activity): string
    {
        if ($status === CalculatedStatus::Active) {
            return $activity === null
                ? 'Recently joined; within the first '.$this->config->activePeriodDays.' days.'
                : $activity->type->reason().' on '.$activity->activityDate->format('d M Y').'.';
        }

        $threshold = $status === CalculatedStatus::Pending
            ? $this->config->activePeriodDays
            : $this->config->inactiveThresholdDays();

        $since = $activity === null ? ' since joining' : '';

        return "No qualifying activity for {$threshold} days{$since} ({$days} days).";
    }
}

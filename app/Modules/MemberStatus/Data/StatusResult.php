<?php

namespace App\Modules\MemberStatus\Data;

use App\Modules\MemberStatus\Enums\CalculatedStatus;
use Carbon\CarbonImmutable;

/**
 * What the engine decided, and everything needed to explain the decision.
 *
 * The engine is a pure function that returns one of these. It writes nothing;
 * persistence is somebody else's job. That is what makes the 90/180 rules
 * testable without a database (spec §25).
 */
final class StatusResult
{
    /**
     * @param  CalculatedStatus  $status  the status as of `asOf`
     * @param  CarbonImmutable|null  $lastActivityAt  last qualifying activity, null if the member has never had any
     * @param  CarbonImmutable  $referenceDate  the date the clock is counted from — activity date, or joining date for a member with no activity
     * @param  int  $daysSinceActivity  whole days from `referenceDate` to `asOf`, never negative
     * @param  string  $reason  audit line explaining this status
     * @param  bool  $reactivationBlocked  true when activity would have restored ACTIVE but reactivation from INACTIVE is switched off
     */
    public function __construct(
        public readonly CalculatedStatus $status,
        public readonly ?CarbonImmutable $lastActivityAt,
        public readonly CarbonImmutable $referenceDate,
        public readonly int $daysSinceActivity,
        public readonly string $reason,
        public readonly ?QualifyingActivity $activity = null,
        public readonly bool $reactivationBlocked = false,
    ) {}

    public function is(CalculatedStatus $status): bool
    {
        return $this->status === $status;
    }

    /** True when the member has never had any qualifying activity at all. */
    public function hasNeverBeenActive(): bool
    {
        return $this->lastActivityAt === null;
    }
}

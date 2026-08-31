<?php

namespace App\Modules\MemberStatus\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * "Today", in one place.
 *
 * Everything in this module dates from whole days, and every comparison is made
 * on immutable, start-of-day values — a status that flips depending on the time
 * of day the job happens to run would be a bug.
 *
 * It goes through Illuminate\Support\Carbon rather than CarbonImmutable::now()
 * so that `travelTo()` in tests is honoured.
 */
final class Clock
{
    public static function today(): CarbonImmutable
    {
        return Carbon::now()->toImmutable()->startOfDay();
    }

    public static function at(CarbonImmutable|string|null $date): CarbonImmutable
    {
        if ($date === null) {
            return self::today();
        }

        if ($date instanceof CarbonImmutable) {
            return $date->startOfDay();
        }

        return CarbonImmutable::parse($date)->startOfDay();
    }

    /**
     * Whole days between two dates, floored at zero.
     *
     * Never negative: a reference date in the future (a member who joined
     * tomorrow, a back-dated clock) means "no days of inactivity have passed",
     * not a negative streak (spec §10).
     */
    public static function daysBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $days = (int) $from->startOfDay()->diffInDays($to->startOfDay(), false);

        return max(0, $days);
    }
}

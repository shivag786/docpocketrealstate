<?php

namespace App\Enums;

/**
 * What a target verdict says about one month.
 *
 * Derived from `achieved` and the window, never stored — see
 * TargetCalculation::outcome(). Target 1 only ever produces Achieved or Missed,
 * because its window is a single month and always closes in the month it opens.
 * InProgress exists for Targets 2 and 3, where a window spans months and "not
 * achieved yet" is genuinely different from "did not achieve".
 */
enum TargetOutcome: string
{
    case Achieved = 'achieved';
    case InProgress = 'in_progress';
    case Missed = 'missed';

    public function label(): string
    {
        return match ($this) {
            self::Achieved => 'Achieved',
            self::InProgress => 'In progress',
            self::Missed => 'Not reached',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Achieved => 'text-bg-success',
            self::InProgress => 'text-bg-info',
            self::Missed => 'text-bg-secondary',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Achieved => 'bi-trophy-fill',
            self::InProgress => 'bi-hourglass-split',
            self::Missed => 'bi-dash-circle',
        };
    }
}

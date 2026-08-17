<?php

namespace App\Enums;

enum CalculationRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * Replaced by a later run of the same period and type.
     *
     * Figures are recalculated every time a sale is entered, so a month
     * accumulates many runs. The superseded ones are kept — they record who
     * calculated what and when — but their results have been deleted and only
     * the newest completed run holds live figures.
     */
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Superseded => 'Superseded',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Running => 'text-bg-warning',
            self::Completed => 'text-bg-success',
            self::Failed => 'text-bg-danger',
            self::Superseded => 'text-bg-light border',
        };
    }
}

<?php

namespace App\Enums;

enum CalculationRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Running => 'text-bg-warning',
            self::Completed => 'text-bg-success',
            self::Failed => 'text-bg-danger',
        };
    }
}

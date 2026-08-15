<?php

namespace App\Enums;

enum CalculationRunType: string
{
    case Direct = 'direct';
    case Upline = 'upline';
    /** Team sales rollup — a measurement, not a reward. Pays nobody. */
    case TeamSales = 'team_sales';
    case Target = 'target';
    case CompanyClub = 'company_club';

    public function label(): string
    {
        return match ($this) {
            self::Direct => 'Direct Reward',
            self::Upline => 'Upline Reward',
            self::TeamSales => 'Team Sales',
            self::Target => 'Team Targets',
            self::CompanyClub => 'Company Club',
        };
    }

    /**
     * The reward type this run produces, or null when it produces no reward.
     */
    public function rewardType(): ?RewardType
    {
        return match ($this) {
            self::Direct => RewardType::Direct,
            self::Upline => RewardType::Upline,
            self::TeamSales => null,
            self::Target => RewardType::Target,
            self::CompanyClub => RewardType::CompanyClub,
        };
    }

    public function phase(): int
    {
        return match ($this) {
            self::TeamSales => 7,
            default => $this->rewardType()->phase(),
        };
    }
}

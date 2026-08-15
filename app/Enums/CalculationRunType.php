<?php

namespace App\Enums;

enum CalculationRunType: string
{
    case Direct = 'direct';
    case Upline = 'upline';
    case Target = 'target';
    case CompanyClub = 'company_club';

    public function label(): string
    {
        return match ($this) {
            self::Direct => 'Direct Reward',
            self::Upline => 'Upline Reward',
            self::Target => 'Team Targets',
            self::CompanyClub => 'Company Club',
        };
    }

    public function rewardType(): RewardType
    {
        return match ($this) {
            self::Direct => RewardType::Direct,
            self::Upline => RewardType::Upline,
            self::Target => RewardType::Target,
            self::CompanyClub => RewardType::CompanyClub,
        };
    }

    public function phase(): int
    {
        return $this->rewardType()->phase();
    }
}

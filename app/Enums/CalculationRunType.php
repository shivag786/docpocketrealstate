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

    /**
     * The reward types whose PAYMENT freezes this engine.
     *
     * Client-confirmed 2026-09-01: paying one engine must not freeze another.
     * Company Club and Team Target are separate money with separate approval,
     * and confirming a Club share has no business stopping a Target verdict
     * from being rebuilt. So the lock is per engine, not per month.
     *
     * TEAM SALES IS THE ONE CROSS-ENGINE ENTRY, and it is not an exception to
     * the rule but the rule applied honestly. Team Sales pays nobody, so it can
     * never be locked by its own payment - but Target's verdict is read off the
     * rollup Team Sales produces. Re-running it after a Target reward was paid
     * would move the ground that payment stood on, which is exactly what the
     * lock exists to prevent.
     *
     * @return list<RewardType>
     */
    public function lockedBy(): array
    {
        return match ($this) {
            self::Direct => [RewardType::Direct],
            self::Upline => [RewardType::Upline],
            self::TeamSales => [RewardType::Target],
            self::Target => [RewardType::Target],
            self::CompanyClub => [RewardType::CompanyClub],
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

<?php

namespace App\Enums;

/**
 * The four independent reward calculations.
 *
 * docs/02_BUSINESS_RULES.md §8: Direct ₹40, Upline ₹50, Target ₹30 and Company
 * Club ₹30 must never be mixed. They are separate engines, separate ledger types
 * and separate source records. This enum is what keeps them separable in the
 * ledger — no query should ever sum across types without saying why.
 */
enum RewardType: string
{
    case Direct = 'direct';
    case Upline = 'upline';
    case Target = 'target';
    case CompanyClub = 'company_club';

    public function label(): string
    {
        return match ($this) {
            self::Direct => 'Direct Sale',
            self::Upline => 'Upline',
            self::Target => 'Team Target',
            self::CompanyClub => 'Company Club',
        };
    }

    /**
     * The confirmed rate per Sq.Ft. for this reward type.
     */
    public function rate(): string
    {
        return (string) match ($this) {
            self::Direct => config('rewards.rates.direct'),
            self::Upline => config('rewards.rates.upline'),
            self::Target => config('rewards.rates.target'),
            self::CompanyClub => config('rewards.rates.company_club'),
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Direct => 'text-bg-primary',
            self::Upline => 'text-bg-info',
            self::Target => 'text-bg-warning',
            self::CompanyClub => 'text-bg-dark',
        };
    }

    public function phase(): int
    {
        return match ($this) {
            self::Direct => 5,
            self::Upline => 6,
            self::Target => 8,
            self::CompanyClub => 11,
        };
    }
}

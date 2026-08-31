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

    /**
     * Whether this engine appears in the back office at all.
     *
     * A HIDDEN ENGINE IS STILL A RUNNING ENGINE. `config('rewards.visibility')`
     * controls what an operator sees, never what is calculated or paid — see
     * the note there. Upline is hidden as of 2026-08-27 at the client's
     * request; it keeps writing ₹50 per Sq.Ft. to the ledger and reconciliation
     * keeps checking it.
     */
    public function isVisible(): bool
    {
        return (bool) config('rewards.visibility.'.$this->value, true);
    }

    /**
     * The engines an operator can see, in declaration order.
     *
     * Every screen that lists reward types uses this rather than `cases()`, so
     * hiding one is a config change rather than an edit in a dozen views.
     *
     * @return list<self>
     */
    public static function visible(): array
    {
        return array_values(array_filter(self::cases(), fn (self $type) => $type->isVisible()));
    }

    /**
     * The same list as raw enum values, for `whereIn` on the ledger.
     *
     * @return list<string>
     */
    public static function visibleValues(): array
    {
        return array_map(fn (self $type) => $type->value, self::visible());
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

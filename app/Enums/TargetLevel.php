<?php

namespace App\Enums;

use App\Support\Money;

/**
 * The three team targets, and everything that distinguishes them.
 *
 * Client-confirmed 2026-08-18: the thresholds and month counts are FIXED IN
 * CODE. The earlier plan to make Targets 2 and 3 admin-configurable was dropped
 * by the client in the same breath as confirming their values — "no need to make
 * any option from admin". `target_calculations` still freezes the threshold and
 * the rate onto every verdict, so a historical run stays reproducible even if
 * these constants are ever edited.
 *
 * CLIENT-CONFIRMED 2026-08-25 — each target now wins a FIXED PRIZE:
 *
 *      Target 1   5,000 Sq.Ft. / 1 month   =>    ₹50,000
 *      Target 2  10,000 Sq.Ft. / 2 months  =>   ₹200,000
 *      Target 3  35,000 Sq.Ft. / 3 months  =>   ₹700,000
 *
 * This replaced threshold × ₹30 (₹150,000 / ₹300,000 / ₹1,050,000). The prizes
 * cannot be expressed as one shared rate — they work out at ₹10, ₹20 and ₹20 per
 * Sq.Ft. — so the prize is now the figure the engine reads, and `rate()` is
 * derived from it rather than the other way round.
 */
enum TargetLevel: int
{
    case One = 1;
    case Two = 2;
    case Three = 3;

    /** Sq.Ft. the member's team must reach inside the window. */
    public function sqft(): string
    {
        return Money::of(config('rewards.targets.'.$this->value.'.sqft'));
    }

    /** How many calendar months the window spans. */
    public function months(): int
    {
        return (int) config('rewards.targets.'.$this->value.'.months');
    }

    /**
     * ₹ per Sq.Ft. — DERIVED from the prize, not the source of it.
     *
     * It exists so `sqft × rate = amount` still holds on every reward_ledger
     * row, which is what reconciliation reads. Nothing calculates a payout from
     * it, and the three targets no longer share one value.
     */
    public function rate(): string
    {
        return Money::of(config('rewards.targets.'.$this->value.'.rate'));
    }

    /**
     * The prize for achieving this target.
     *
     * A FIXED amount, read straight from config — not threshold × rate, and not
     * a function of what the team actually sold. A team doing 7,000 against
     * Target 1 wins the same ₹50,000 as one doing exactly 5,000.
     */
    public function reward(): string
    {
        return Money::of(config('rewards.targets.'.$this->value.'.reward'));
    }

    public function label(): string
    {
        return match ($this) {
            self::One => 'One Month Target',
            self::Two => 'Two Month Target',
            self::Three => 'Three Month Target',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::One => 'Target 1',
            self::Two => 'Target 2',
            self::Three => 'Target 3',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::One => 'text-bg-warning',
            self::Two => 'text-bg-primary',
            self::Three => 'text-bg-success',
        };
    }

    /**
     * The target that opens the month AFTER this one is achieved.
     *
     * Null on Target 3: a member who achieves it has finished the ladder and is
     * never measured again.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::One => self::Two,
            self::Two => self::Three,
            self::Three => null,
        };
    }

    /** @return array<int, self> */
    public static function all(): array
    {
        return [self::One, self::Two, self::Three];
    }
}

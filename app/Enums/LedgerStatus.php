<?php

namespace App\Enums;

/**
 * The life of a reward amount.
 *
 * Client-confirmed 2026-08-17: a reward is calculated continuously as sales
 * arrive and stays PROVISIONAL for the whole month — every recalculation may
 * change it. It becomes real only when an admin explicitly confirms payment.
 *
 * `Posted` therefore means "calculated, and still free to change". `Paid` means
 * an admin confirmed it, and from that moment the amount is frozen: the period
 * it belongs to can no longer be recalculated.
 *
 * Still deliberately NOT invented: Held, Reversed, Cancelled. Each would need a
 * confirmed rule about when it applies and what it does to reconciliation.
 */
enum LedgerStatus: string
{
    /** Calculated. Provisional — recalculation may still change or remove it. */
    case Posted = 'posted';

    /** Payment confirmed by an admin. Frozen; locks its period. */
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Posted => 'Not paid',
            self::Paid => 'Paid',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Posted => 'text-bg-secondary',
            self::Paid => 'text-bg-success',
        };
    }
}

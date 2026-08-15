<?php

namespace App\Enums;

/**
 * OPEN QUESTION: the documentation defines a `status` column on reward_ledger
 * but never its values, and there is no payment or settlement workflow anywhere
 * in the business rules.
 *
 * `Posted` is therefore the only state: the reward has been calculated and
 * recorded. States such as Paid, Held or Reversed are NOT invented here — they
 * would each need a confirmed rule about when they apply and what they do to
 * reconciliation.
 */
enum LedgerStatus: string
{
    case Posted = 'posted';

    public function label(): string
    {
        return match ($this) {
            self::Posted => 'Posted',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Posted => 'text-bg-success',
        };
    }
}

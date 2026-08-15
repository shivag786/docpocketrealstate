<?php

namespace App\Enums;

/**
 * Registry sale status.
 *
 * CLIENT-CONFIRMED (2026-08-15): entering a sale IS approval. The admin records
 * the sale after the registry is done, so a sale counts toward rewards from the
 * moment it is entered. There is no pending state and no approval step.
 *
 * The single case is deliberate. `Approved` is the only state the business has
 * defined, and every calculation rule filters on "approved sale". Cancellation
 * and refunds are explicitly out of scope (docs/02_BUSINESS_RULES.md §6), so no
 * other state is invented here. If reversal is ever required it must arrive as a
 * confirmed business rule, and the calculation engines must then be told how an
 * already-paid reward is unwound.
 */
enum SaleStatus: string
{
    case Approved = 'approved';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Approved',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Approved => 'text-bg-success',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $s) => [$s->value => $s->label()])
            ->all();
    }
}

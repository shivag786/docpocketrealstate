<?php

namespace App\Enums;

/**
 * OPEN QUESTION: the documentation defines a `status` column for properties but
 * never its values. Active/Inactive is used here for consistency with the rest
 * of the system, and only controls whether a site can be picked for a new sale.
 *
 * If the business actually wants availability tracking (Available / Sold /
 * Blocked), that is a different concept and must be confirmed before it is
 * built — it is not invented here.
 */
enum PropertyStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Active => 'text-bg-success',
            self::Inactive => 'text-bg-secondary',
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

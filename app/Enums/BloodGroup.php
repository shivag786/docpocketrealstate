<?php

namespace App\Enums;

/**
 * The eight ABO/Rh groups.
 *
 * An enum rather than an admin-editable list, unlike designations: this set is
 * fixed by biology, not by the business, and a typo'd blood group on a printed
 * ID card is the kind of error nobody catches until it matters.
 *
 * A member's blood group is optional — see the members table migration — so the
 * absence of a case here is a real state and every screen must render it as
 * "not recorded" rather than an empty cell.
 */
enum BloodGroup: string
{
    case APositive = 'A+';
    case ANegative = 'A-';
    case BPositive = 'B+';
    case BNegative = 'B-';
    case ABPositive = 'AB+';
    case ABNegative = 'AB-';
    case OPositive = 'O+';
    case ONegative = 'O-';

    public function label(): string
    {
        return $this->value;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $group) => [$group->value => $group->label()])
            ->all();
    }
}

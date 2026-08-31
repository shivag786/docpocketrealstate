<?php

namespace App\Modules\MemberStatus\Enums;

/**
 * The three statuses this module calculates.
 *
 * DELIBERATELY SEPARATE from App\Enums\MemberStatus. The host application's
 * enum has two cases (active/inactive) and drives existing behaviour such as
 * sale entry validation. This module owns a third state, PENDING, and must not
 * widen or reuse the application's enum (spec §21).
 *
 * The stored values are upper case because the specification writes them that
 * way and because a value read out of `member_status_snapshot` should be
 * impossible to confuse with a value read out of `members.status`.
 */
enum CalculatedStatus: string
{
    case Active = 'ACTIVE';
    case Pending = 'PENDING';
    case Inactive = 'INACTIVE';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Pending => 'Pending',
            self::Inactive => 'Inactive',
        };
    }

    /** Bootstrap 5 badge class, matching the host application's conventions. */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Active => 'text-bg-success',
            self::Pending => 'text-bg-warning',
            self::Inactive => 'text-bg-secondary',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}

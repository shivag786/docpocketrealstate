<?php

namespace App\Enums;

/**
 * Back-office roles. Members of the MLM network are NOT users and never
 * authenticate — sales and rewards are entered by staff only.
 *
 * See docs/02_BUSINESS_RULES.md §7 (Admin Control).
 */
enum UserRole: string
{
    case Admin = 'admin';
    case Manager = 'manager';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Manager => 'Manager',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $role) => [$role->value => $role->label()])
            ->all();
    }
}

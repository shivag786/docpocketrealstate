<?php

namespace App\Enums;

/**
 * Client-confirmed: members are Active or Inactive. Nothing else.
 *
 * NOTE: it is still unconfirmed whether an Inactive member is skipped when
 * walking the sponsor chain for upline rewards (docs/PROJECT_STATE.md, open
 * question 3). Phase 6 must not assume an answer.
 */
enum MemberStatus: string
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
            ->mapWithKeys(fn (self $status) => [$status->value => $status->label()])
            ->all();
    }
}

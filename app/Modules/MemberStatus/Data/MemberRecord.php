<?php

namespace App\Modules\MemberStatus\Data;

use Carbon\CarbonImmutable;

/**
 * The minimum a member has to look like for the status engine (spec §12).
 *
 * The engine never touches an Eloquent model. Whatever the host application
 * calls its columns, a MemberProvider maps them onto these four facts, so the
 * field names on the far side stay configurable and the engine stays stable.
 */
final class MemberRecord
{
    public function __construct(
        public readonly int|string $id,
        public readonly ?int $sponsorId,
        public readonly CarbonImmutable $joinedAt,
        public readonly ?string $name = null,
        public readonly ?string $code = null,
        // Display only, for the payment panel: an admin confirming money wants
        // to see who they are paying, not just an id.
        public readonly ?string $mobile = null,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        return new self(
            id: $attributes['id'],
            sponsorId: isset($attributes['sponsor_id']) ? (int) $attributes['sponsor_id'] : null,
            joinedAt: CarbonImmutable::parse($attributes['joined_at'])->startOfDay(),
            name: isset($attributes['name']) ? (string) $attributes['name'] : null,
            code: isset($attributes['code']) ? (string) $attributes['code'] : null,
            mobile: isset($attributes['mobile']) ? (string) $attributes['mobile'] : null,
        );
    }
}

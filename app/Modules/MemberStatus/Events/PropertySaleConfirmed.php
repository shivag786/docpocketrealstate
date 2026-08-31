<?php

namespace App\Modules\MemberStatus\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * "A valid property sale has been confirmed" — the module's own event (spec §24).
 *
 * DELIBERATELY DEFINED HERE, not in the host application. Nothing in the
 * existing codebase dispatches it today, and the existing sale controller was
 * not touched to make it do so. Dispatching it is a one-line integration the
 * client can make later; until then the scheduled command keeps every status
 * correct on its own.
 *
 * See MEMBER_STATUS_INTEGRATION.md §7 for the exact line and where it goes.
 */
class PropertySaleConfirmed
{
    use Dispatchable;

    public function __construct(
        public readonly int|string $saleId,
        public readonly int|string $sellerMemberId,
        public readonly CarbonImmutable $saleDate,
    ) {}

    /**
     * Convenience constructor for a host application that has a sale model and
     * would rather not build the CarbonImmutable itself.
     */
    public static function make(int|string $saleId, int|string $sellerMemberId, mixed $saleDate): self
    {
        return new self(
            saleId: $saleId,
            sellerMemberId: $sellerMemberId,
            saleDate: CarbonImmutable::parse((string) $saleDate)->startOfDay(),
        );
    }
}

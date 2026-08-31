<?php

namespace App\Modules\MemberStatus\Data;

use App\Modules\MemberStatus\Enums\ActivityType;
use Carbon\CarbonImmutable;

/**
 * A single qualifying event for one member.
 *
 * `memberId` is who the activity belongs to. `sourceMemberId` is whose sale
 * produced it — the same person for an own sale, the direct referral for a
 * referral sale. Keeping both is what makes the report able to say "Shiva is
 * active because A sold on 20 Aug" (spec §20).
 */
final class QualifyingActivity
{
    public function __construct(
        public readonly int|string $memberId,
        public readonly ActivityType $type,
        public readonly int|string $sourceMemberId,
        public readonly int|string|null $saleId,
        public readonly CarbonImmutable $activityDate,
    ) {}

    public static function ownSale(int|string $memberId, SaleRecord $sale): self
    {
        return new self(
            memberId: $memberId,
            type: ActivityType::OwnSale,
            sourceMemberId: $sale->memberId,
            saleId: $sale->id,
            activityDate: $sale->soldAt->startOfDay(),
        );
    }

    public static function directReferralSale(int|string $sponsorId, SaleRecord $sale): self
    {
        return new self(
            memberId: $sponsorId,
            type: ActivityType::DirectReferralSale,
            sourceMemberId: $sale->memberId,
            saleId: $sale->id,
            activityDate: $sale->soldAt->startOfDay(),
        );
    }
}

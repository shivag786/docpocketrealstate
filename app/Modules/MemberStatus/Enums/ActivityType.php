<?php

namespace App\Modules\MemberStatus\Enums;

/**
 * How a member came by a piece of qualifying activity (spec §20).
 *
 * There are exactly two kinds, and there will never be a third for deeper
 * levels: a level-2 sale is not activity for anybody but the seller and the
 * seller's direct sponsor (spec §3).
 */
enum ActivityType: string
{
    /** The member sold the property themselves. */
    case OwnSale = 'OWN_SALE';

    /** A member the member personally referred sold a property. */
    case DirectReferralSale = 'DIRECT_REFERRAL_SALE';

    public function label(): string
    {
        return match ($this) {
            self::OwnSale => 'Own sale',
            self::DirectReferralSale => 'Direct referral sale',
        };
    }

    /** The human-readable reason recorded against a status change. */
    public function reason(): string
    {
        return match ($this) {
            self::OwnSale => 'Own property sale',
            self::DirectReferralSale => 'Direct referral property sale',
        };
    }
}

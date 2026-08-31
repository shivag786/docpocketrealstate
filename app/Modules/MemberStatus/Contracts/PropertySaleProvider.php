<?php

namespace App\Modules\MemberStatus\Contracts;

use App\Modules\MemberStatus\Data\QualifyingActivity;
use App\Modules\MemberStatus\Data\SaleRecord;
use Carbon\CarbonImmutable;

/**
 * Where the module gets valid property sales from (spec §11, §14).
 *
 * THIS IS THE ONLY PLACE that decides whether a sale is valid. Cancelled,
 * rejected, deleted, failed, draft and unconfirmed sales must never be
 * returned by an implementation; nothing downstream re-checks.
 *
 * Implementations must not write to the host application's sale tables.
 */
interface PropertySaleProvider
{
    /**
     * Valid sales made by the member themselves, newest first.
     *
     * @return list<SaleRecord>
     */
    public function getOwnSales(int|string $memberId, ?CarbonImmutable $since = null): array;

    /**
     * Valid sales made by the member's DIRECT referrals, newest first.
     *
     * Level 1 only. A sale by a referral's referral is not returned here.
     *
     * @return list<SaleRecord>
     */
    public function getDirectReferralSales(int|string $memberId, ?CarbonImmutable $since = null): array;

    /**
     * The member's most recent qualifying activity of either kind, or null.
     *
     * `$asOf` bounds the search so a status can be recalculated for a past date
     * without a future sale leaking into the answer.
     */
    public function getLastQualifyingActivity(
        int|string $memberId,
        ?CarbonImmutable $asOf = null,
    ): ?QualifyingActivity;

    /**
     * The most recent qualifying activity for many members at once, keyed by
     * member id. Members with no activity are simply absent from the result.
     *
     * The batch job uses this and never `getLastQualifyingActivity()` in a
     * loop; that is the difference between a fixed number of queries and two
     * per member (spec §31).
     *
     * @param  list<int|string>  $memberIds
     * @return array<int|string, QualifyingActivity>
     */
    public function getLastQualifyingActivityForMany(
        array $memberIds,
        ?CarbonImmutable $asOf = null,
    ): array;

    /**
     * The most recent activity of EACH kind for many members at once:
     *
     *     [ memberId => [ 'OWN_SALE' => ..., 'DIRECT_REFERRAL_SALE' => ... ] ]
     *
     * The report needs both separately ("Own Sale Activity" and "Direct
     * Referral Activity" columns, spec §27), and `getLastQualifyingActivityForMany()`
     * is the newer of the two, so an implementation can derive that from this.
     *
     * @param  list<int|string>  $memberIds
     * @return array<int|string, array<string, QualifyingActivity>>
     */
    public function getLatestActivityByTypeForMany(
        array $memberIds,
        ?CarbonImmutable $asOf = null,
    ): array;

    /**
     * One sale by id, or null when it is unknown OR not valid.
     *
     * Used by the event path: a sale that has been cancelled between the event
     * being queued and the listener running must come back null.
     */
    public function findValidSale(int|string $saleId): ?SaleRecord;
}

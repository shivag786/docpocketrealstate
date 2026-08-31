<?php

namespace App\Modules\MemberStatus\Services;

use App\Modules\MemberStatus\Contracts\MemberProvider;
use App\Modules\MemberStatus\Contracts\PropertySaleProvider;
use App\Modules\MemberStatus\Data\QualifyingActivity;
use App\Modules\MemberStatus\Data\SaleRecord;
use App\Modules\MemberStatus\Data\StatusOutcome;
use Carbon\CarbonImmutable;

/**
 * Turns one confirmed sale into qualifying activity (spec §18, §24).
 *
 * EXACTLY TWO MEMBERS can be affected by a sale:
 *
 *      the seller                      -> OWN_SALE
 *      the seller's direct sponsor     -> DIRECT_REFERRAL_SALE
 *
 * and nobody else. The grandparent gets nothing. For Shiva -> A -> A1 -> A2, a
 * sale by A2 is activity for A2 and A1 only; A and Shiva are untouched. There
 * is no loop over ancestors in this class and there must never be one — that
 * single rule is what the module exists to enforce (spec §3, §19).
 *
 * The recorder is safe to call twice for the same sale: the activity ledger is
 * keyed on (member, type, sale), and recalculating a status that has not
 * changed writes no history.
 */
class SaleActivityRecorder
{
    public function __construct(
        private readonly MemberProvider $members,
        private readonly PropertySaleProvider $sales,
        private readonly StatusRecalculationService $recalculation,
    ) {}

    /**
     * Record activity for a sale by id, then recalculate the affected members.
     *
     * Returns an empty list when the sale is unknown or no longer valid — a
     * sale that was cancelled between the event firing and this running must
     * produce no activity at all (spec §11).
     *
     * @return list<StatusOutcome>
     */
    public function recordSale(int|string $saleId, ?CarbonImmutable $asOf = null): array
    {
        $sale = $this->sales->findValidSale($saleId);

        if ($sale === null) {
            return [];
        }

        return $this->record($sale, $asOf);
    }

    /**
     * Record activity for an already-resolved, already-valid sale.
     *
     * @return list<StatusOutcome>
     */
    public function record(SaleRecord $sale, ?CarbonImmutable $asOf = null): array
    {
        $affected = $this->affectedMemberIds($sale);

        if ($affected === []) {
            return [];
        }

        // Recalculation re-reads the latest activity from the sale provider and
        // mirrors it into the ledger itself, so this call both records and
        // judges. Only the ids in $affected are passed, which is what keeps the
        // blast radius at one level.
        return $this->recalculation->recalculateMembers($affected, $asOf);
    }

    /**
     * The activity rows this sale produces, without touching the database.
     *
     * Exposed for tests and for anyone who wants to see the rule applied
     * without any writes.
     *
     * @return list<QualifyingActivity>
     */
    public function activitiesFor(SaleRecord $sale): array
    {
        $activities = [QualifyingActivity::ownSale($sale->memberId, $sale)];

        $sponsorId = $this->members->sponsorIdOf($sale->memberId);

        if ($sponsorId !== null) {
            $activities[] = QualifyingActivity::directReferralSale($sponsorId, $sale);
        }

        return $activities;
    }

    /**
     * The seller, plus their direct sponsor if they have one. Never more.
     *
     * @return list<int|string>
     */
    public function affectedMemberIds(SaleRecord $sale): array
    {
        if ($this->members->find($sale->memberId) === null) {
            return [];
        }

        $ids = [$sale->memberId];

        $sponsorId = $this->members->sponsorIdOf($sale->memberId);

        // One step up. Not a walk — there is no while loop here on purpose.
        if ($sponsorId !== null && $this->members->find($sponsorId) !== null) {
            $ids[] = $sponsorId;
        }

        return $ids;
    }
}

<?php

namespace App\Modules\MemberStatus\Listeners;

use App\Modules\MemberStatus\Events\PropertySaleConfirmed;
use App\Modules\MemberStatus\Services\SaleActivityRecorder;

/**
 * Event path: a confirmed sale updates two statuses immediately (spec §24).
 *
 * The sale is re-read through the PropertySaleProvider and is only acted on if
 * the provider still considers it valid. The event payload is never trusted as
 * proof that a sale happened — otherwise anything able to dispatch an event
 * could fabricate activity, which spec §30 forbids. A sale cancelled between
 * the event firing and this listener running therefore produces nothing, which
 * is the correct outcome.
 *
 * Synchronous by design: it touches at most two members. Dispatch
 * Jobs\RecalculateMemberStatusJob instead if a sale-entry request must not wait
 * for it.
 */
class RecordQualifyingActivity
{
    public function __construct(
        private readonly SaleActivityRecorder $recorder,
    ) {}

    public function handle(PropertySaleConfirmed $event): void
    {
        // recordSale() identifies the seller and the seller's DIRECT sponsor,
        // records their activity and recalculates exactly those two statuses.
        // Nobody above them is touched (spec §18).
        $this->recorder->recordSale($event->saleId);
    }
}

<?php

namespace App\Modules\MemberStatus\Services;

use App\Modules\MemberStatus\Data\PaymentEligibility;
use App\Modules\MemberStatus\Enums\CalculatedStatus;
use App\Modules\MemberStatus\Models\MemberStatusSnapshot;
use App\Modules\MemberStatus\Repositories\StatusSnapshotRepository;
use App\Modules\MemberStatus\Support\StatusConfig;

/**
 * The payment gate (client rule, 2026-08-25).
 *
 *      A member who is not ACTIVE can be looked at in full — every reward,
 *      every amount, paid and unpaid — but an admin may not confirm a payment
 *      to them.
 *
 * This class is the ONLY place that rule is written. The button's disabled
 * state, the tooltip, and the server-side refusal all ask this same question,
 * so a disabled button and a blocked request can never disagree — and a
 * hand-crafted POST is refused exactly like the button is.
 *
 * Which statuses block comes from config; nothing here hard-codes PENDING.
 */
class PaymentEligibilityService
{
    public function __construct(
        private readonly StatusSnapshotRepository $snapshots,
        private readonly StatusConfig $config,
    ) {}

    public function check(int|string $memberId): PaymentEligibility
    {
        return $this->fromSnapshot($this->snapshots->find($memberId));
    }

    /**
     * Decide for many members at once — one query for a whole page of rows.
     *
     * @param  list<int|string>  $memberIds
     * @return array<int|string, PaymentEligibility>
     */
    public function checkMany(array $memberIds): array
    {
        $snapshots = $this->snapshots->findMany($memberIds);

        $decisions = [];

        foreach ($memberIds as $memberId) {
            $decisions[$memberId] = $this->fromSnapshot($snapshots[$memberId] ?? null);
        }

        return $decisions;
    }

    /**
     * Decide from an already-loaded snapshot, without touching the database.
     */
    public function fromSnapshot(?MemberStatusSnapshot $snapshot): PaymentEligibility
    {
        if ($snapshot === null) {
            // Never calculated. An unknown status is not evidence of
            // inactivity, so by default it does not stop a payment that would
            // work today — see `block_when_unknown` in the config.
            return $this->config->blockPaymentWhenUnknown
                ? PaymentEligibility::block(
                    null,
                    'This member has no calculated status yet. Run the member status calculation before confirming payments.',
                )
                : PaymentEligibility::allow(null);
        }

        if (! $this->blocks($snapshot->status)) {
            return PaymentEligibility::allow($snapshot->status, $snapshot->days_since_activity);
        }

        return PaymentEligibility::block(
            $snapshot->status,
            $this->reason($snapshot),
            $snapshot->days_since_activity,
        );
    }

    public function blocks(CalculatedStatus $status): bool
    {
        return in_array($status->value, $this->config->paymentBlockedStatuses, true);
    }

    /**
     * The sentence an admin reads when the button will not press.
     *
     * It names the status, the gap, and what would clear it, because "not
     * allowed" on its own turns into a phone call.
     */
    private function reason(MemberStatusSnapshot $snapshot): string
    {
        $last = $snapshot->last_activity_at?->format('d M Y');

        $because = $last === null
            ? 'no qualifying sale has ever been recorded for them'
            : sprintf('their last qualifying sale was on %s', $last);

        return sprintf(
            'Payment is on hold: this member is %s — %s, %d days ago. '
            .'A sale by them or by one of their direct referrals returns them to Active and re-enables payment.',
            strtoupper($snapshot->status->label()),
            $because,
            $snapshot->days_since_activity,
        );
    }
}

<?php

namespace App\Modules\MemberStatus\Services;

use App\Modules\MemberStatus\Contracts\MemberProvider;
use App\Modules\MemberStatus\Contracts\RewardGateway;
use App\Modules\MemberStatus\Data\MemberRecord;
use App\Modules\MemberStatus\Repositories\StatusSnapshotRepository;
use App\Modules\MemberStatus\Support\Clock;

/**
 * Everything the payment panel shows about one member, in one payload.
 *
 * Assembled server-side and returned whole — including after a payment — so the
 * modal always redraws from the truth rather than patching what it thinks
 * changed. A stale "unpaid" total on a money screen is worse than a slow one.
 *
 * The panel deliberately shows a blocked member EVERYTHING: their rewards,
 * their amounts, what is paid and what is not. Only the confirm action is
 * withheld (client rule, 2026-08-25).
 */
class RewardPanelService
{
    public function __construct(
        private readonly MemberProvider $members,
        private readonly RewardGateway $rewards,
        private readonly StatusSnapshotRepository $snapshots,
        private readonly PaymentEligibilityService $eligibility,
    ) {}

    /**
     * @return array<string, mixed>|null null when the id is not a member
     */
    public function forMember(int|string $memberId): ?array
    {
        $member = $this->members->find($memberId);

        if ($member === null) {
            return null;
        }

        $snapshot = $this->snapshots->find($memberId);
        $eligibility = $this->eligibility->fromSnapshot($snapshot);
        $rewards = $this->rewards->rewardsFor($memberId);

        return [
            'member' => [
                'id' => $member->id,
                'code' => $member->code,
                'name' => $member->name,
                'mobile' => $member->mobile,
                'joined_at' => $member->joinedAt->format('d M Y'),
                'sponsor' => $this->sponsorLabel($member),
                'member_since_days' => Clock::daysBetween($member->joinedAt, Clock::today()),
            ],

            'status' => [
                'value' => $snapshot?->status->value,
                'label' => $snapshot?->status->label() ?? 'Not calculated',
                'badge_class' => $snapshot?->status->badgeClass() ?? 'text-bg-light border',
                'last_activity_at' => $snapshot?->last_activity_at?->format('d M Y'),
                'last_activity_type' => $snapshot?->last_activity_type?->label(),
                'days_since_activity' => $snapshot?->days_since_activity,
                'calculated_at' => $snapshot?->calculated_at?->format('d M Y'),
            ],

            // What the button does, and what the tooltip says when it does not.
            'payment' => [
                'allowed' => $eligibility->allowed,
                'reason' => $eligibility->reason,
            ],

            'summary' => $this->rewards->summaryFor($memberId),

            'rewards' => array_map(fn ($reward) => $reward->toArray(), $rewards),
        ];
    }

    private function sponsorLabel(MemberRecord $member): ?string
    {
        if ($member->sponsorId === null) {
            return null;
        }

        $sponsor = $this->members->find($member->sponsorId);

        if ($sponsor === null) {
            return null;
        }

        return trim(($sponsor->code ?? '#'.$sponsor->id).' — '.($sponsor->name ?? ''));
    }
}

<?php

namespace App\Modules\MemberStatus\Contracts;

use App\Modules\MemberStatus\Data\RewardRow;
use RuntimeException;

/**
 * Where the module reads reward amounts, and how it confirms a payment.
 *
 * The module owns no money. It reads the host application's reward ledger and,
 * when an admin confirms, asks the host's OWN payment service to record the
 * payment — the same code path the existing screens use, with the same locking,
 * the same period rules and the same audit fields. This module adds one
 * condition in front of it and changes nothing behind it.
 *
 * Everything that knows what a reward is stored in lives behind this interface,
 * so the payment screen can be pointed elsewhere, or removed, without the
 * status engine noticing.
 */
interface RewardGateway
{
    /**
     * Every reward belonging to a member, newest period first.
     *
     * @return list<RewardRow>
     */
    public function rewardsFor(int|string $memberId): array;

    public function find(int|string $rewardId): ?RewardRow;

    /**
     * Does this reward belong to this member? Checked before every payment so
     * a swapped id in the request cannot pay somebody else's reward.
     */
    public function belongsToMember(int|string $rewardId, int|string $memberId): bool;

    /**
     * Totals for the member: entries and amounts, paid and unpaid.
     *
     * @return array{total: int, paid: int, unpaid: int, paid_amount: string, unpaid_amount: string, total_amount: string}
     */
    public function summaryFor(int|string $memberId): array;

    /**
     * Confirm one reward as paid.
     *
     * The gateway does NOT decide whether the member is payable — that is
     * PaymentEligibilityService's job, and the caller must ask it first.
     * This method only reports the host application's own refusals: a month
     * that has not ended, a reward already paid.
     *
     * @throws RuntimeException with a message fit to show an admin
     */
    public function markPaid(int|string $rewardId, mixed $confirmedBy): RewardRow;

    /**
     * Unpaid entry count and amount for many members at once, keyed by member id.
     *
     * One query for a whole page of the report. Members with nothing unpaid are
     * absent from the result.
     *
     * @param  list<int|string>  $memberIds
     * @return array<int|string, array{count: int, amount: string}>
     */
    public function unpaidSummaryForMany(array $memberIds): array;

    /**
     * Ids of a member's unpaid rewards, oldest first.
     *
     * @return list<int|string>
     */
    public function unpaidIdsFor(int|string $memberId): array;
}

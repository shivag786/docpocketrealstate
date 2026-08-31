<?php

namespace App\Modules\MemberStatus\Services;

use App\Modules\MemberStatus\Contracts\MemberProvider;
use App\Modules\MemberStatus\Contracts\PropertySaleProvider;
use App\Modules\MemberStatus\Data\MemberRecord;
use App\Modules\MemberStatus\Data\QualifyingActivity;
use App\Modules\MemberStatus\Data\RecalculationSummary;
use App\Modules\MemberStatus\Data\StatusOutcome;
use App\Modules\MemberStatus\Enums\CalculatedStatus;
use App\Modules\MemberStatus\Repositories\StatusActivityRepository;
use App\Modules\MemberStatus\Repositories\StatusHistoryRepository;
use App\Modules\MemberStatus\Repositories\StatusSnapshotRepository;
use App\Modules\MemberStatus\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Puts the engine to work: reads activity, decides status, writes the module's
 * own tables (spec §23).
 *
 * The order is fixed and matters:
 *
 *      latest qualifying activity  (PropertySaleProvider — the source of truth)
 *              |
 *      MemberStatusEngine          (pure decision)
 *              |
 *      snapshot + history + log    (module tables only)
 *
 * Status is always recalculated from the real sales, never from the module's
 * own activity ledger. The ledger is written alongside as an audit record; if
 * it were the input instead, one missed event would freeze a member's status
 * for good.
 *
 * NOTHING in this class writes to `members`, `registry_sales` or any other
 * existing table (spec §21, §37).
 */
class StatusRecalculationService
{
    public function __construct(
        private readonly MemberProvider $members,
        private readonly PropertySaleProvider $sales,
        private readonly MemberStatusEngine $engine,
        private readonly StatusSnapshotRepository $snapshots,
        private readonly StatusHistoryRepository $history,
        private readonly StatusActivityRepository $activities,
        private readonly StatusTransitionLogger $logger,
    ) {}

    /**
     * Recalculate one member. Returns null when the id is not a member.
     */
    public function recalculateMember(
        int|string $memberId,
        ?CarbonImmutable $asOf = null,
        bool $persist = true,
    ): ?StatusOutcome {
        $member = $this->members->find($memberId);

        if ($member === null) {
            return null;
        }

        return $this->recalculateFor([$member], $asOf, $persist)[0] ?? null;
    }

    /**
     * Recalculate a specific set of members — the event path's entry point.
     *
     * Used with exactly two ids after a sale: the seller and their direct
     * sponsor. It never expands the set (spec §18, §24).
     *
     * @param  list<int|string>  $memberIds
     * @return list<StatusOutcome>
     */
    public function recalculateMembers(
        array $memberIds,
        ?CarbonImmutable $asOf = null,
        bool $persist = true,
    ): array {
        $members = array_values($this->members->findMany($memberIds));

        return $this->recalculateFor($members, $asOf, $persist);
    }

    /**
     * Recalculate every member, in batches (spec §23, §31).
     *
     * @param  callable(RecalculationSummary): void|null  $onChunk  progress hook for the console command
     */
    public function recalculateAll(
        ?CarbonImmutable $asOf = null,
        bool $persist = true,
        ?int $chunkSize = null,
        ?callable $onChunk = null,
    ): RecalculationSummary {
        $asOf = Clock::at($asOf);
        $chunkSize = $chunkSize ?? $this->engine->config()->chunkSize;

        $outcomes = [];

        $this->members->chunk($chunkSize, function (array $members) use ($asOf, $persist, $onChunk, &$outcomes) {
            $chunkOutcomes = $this->recalculateFor($members, $asOf, $persist);

            $outcomes = array_merge($outcomes, $chunkOutcomes);

            if ($onChunk !== null) {
                $onChunk(RecalculationSummary::fromOutcomes($chunkOutcomes, $asOf, $persist));
            }
        });

        return RecalculationSummary::fromOutcomes($outcomes, $asOf, $persist);
    }

    /**
     * The one code path every entry point above funnels into.
     *
     * A chunk of members costs a fixed number of queries: one for the existing
     * snapshots, two for the latest activity, then the writes. Per-member
     * lookups here would be the N+1 the specification calls out (spec §31).
     *
     * @param  list<MemberRecord>  $members
     * @return list<StatusOutcome>
     */
    private function recalculateFor(array $members, ?CarbonImmutable $asOf, bool $persist): array
    {
        if ($members === []) {
            return [];
        }

        $asOf = Clock::at($asOf);
        $memberIds = array_map(fn (MemberRecord $member) => $member->id, $members);

        $snapshots = $this->snapshots->findMany($memberIds);
        $activities = $this->sales->getLastQualifyingActivityForMany($memberIds, $asOf);

        $outcomes = [];

        foreach ($members as $member) {
            $previous = $snapshots[$member->id]->status ?? null;
            $activity = $activities[$member->id] ?? null;

            $result = $this->engine->calculate($member, $activity, $previous, $asOf);

            $outcomes[] = new StatusOutcome(
                member: $member,
                previousStatus: $previous,
                result: $result,
                changed: $previous !== $result->status,
                persisted: $persist,
            );
        }

        if ($persist) {
            // One transaction per chunk. A half-written chunk would leave
            // snapshots and history disagreeing about what happened.
            DB::transaction(function () use ($outcomes, $asOf, $activities) {
                foreach ($outcomes as $outcome) {
                    $this->persist($outcome, $activities[$outcome->member->id] ?? null, $asOf);
                }
            });
        }

        return $outcomes;
    }

    private function persist(StatusOutcome $outcome, ?QualifyingActivity $activity, CarbonImmutable $asOf): void
    {
        // Mirror what was observed into the ledger. Idempotent, so a member
        // whose latest activity has not moved since the last run adds nothing.
        if ($activity !== null) {
            $this->activities->record($activity);
        }

        $this->snapshots->store($outcome->member->id, $outcome->result, $asOf, $outcome->changed);

        if (! $outcome->changed) {
            return;
        }

        $this->history->record(
            memberId: $outcome->member->id,
            oldStatus: $outcome->previousStatus,
            newStatus: $outcome->result->status,
            reason: $outcome->result->reason,
            effectiveAt: $asOf,
        );

        $this->logger->transition($outcome, $asOf);
    }

    /**
     * Read the module's stored status without recalculating anything.
     */
    public function currentStatus(int|string $memberId): ?CalculatedStatus
    {
        return $this->snapshots->statusOf($memberId);
    }
}

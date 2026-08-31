<?php

namespace App\Modules\MemberStatus\Jobs;

use App\Modules\MemberStatus\Services\SaleActivityRecorder;
use App\Modules\MemberStatus\Services\StatusRecalculationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Recalculate a small, explicit set of statuses off the request cycle.
 *
 * Two ways to use it:
 *
 *      RecalculateMemberStatusJob::forSale($saleId)         // seller + direct sponsor
 *      RecalculateMemberStatusJob::forMembers([$id, ...])   // exactly these members
 *
 * Neither expands the set. This job is NOT how a whole network is reprocessed —
 * that is `member-status:calculate`, which batches (spec §23, §31).
 *
 * Ids are carried, not models, so the payload cannot go stale and the job never
 * pulls a host application model into the queue.
 */
class RecalculateMemberStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  list<int|string>  $memberIds
     */
    private function __construct(
        public readonly ?string $saleId = null,
        public readonly array $memberIds = [],
    ) {}

    public static function forSale(int|string $saleId): self
    {
        return new self(saleId: (string) $saleId);
    }

    /**
     * @param  list<int|string>  $memberIds
     */
    public static function forMembers(array $memberIds): self
    {
        return new self(memberIds: array_values($memberIds));
    }

    public function handle(SaleActivityRecorder $recorder, StatusRecalculationService $recalculation): void
    {
        if ($this->saleId !== null) {
            $recorder->recordSale($this->saleId);

            return;
        }

        if ($this->memberIds !== []) {
            $recalculation->recalculateMembers($this->memberIds);
        }
    }

    /**
     * One queued job per sale, no matter how many times the event fires.
     */
    public function uniqueId(): string
    {
        return $this->saleId ?? implode('-', $this->memberIds);
    }
}

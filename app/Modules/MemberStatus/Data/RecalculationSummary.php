<?php

namespace App\Modules\MemberStatus\Data;

use App\Modules\MemberStatus\Enums\CalculatedStatus;
use Carbon\CarbonImmutable;

/**
 * The result of a whole run — what the scheduled command prints and what a
 * caller checks to see whether anything moved.
 */
final class RecalculationSummary
{
    /**
     * @param  array<string, int>  $totals  members ending the run in each status
     * @param  list<StatusOutcome>  $transitions  only the members whose status actually changed
     */
    public function __construct(
        public readonly int $processed,
        public readonly array $totals,
        public readonly array $transitions,
        public readonly CarbonImmutable $asOf,
        public readonly bool $persisted,
    ) {}

    public function changed(): int
    {
        return count($this->transitions);
    }

    public function total(CalculatedStatus $status): int
    {
        return $this->totals[$status->value] ?? 0;
    }

    /**
     * @param  list<StatusOutcome>  $outcomes
     */
    public static function fromOutcomes(array $outcomes, CarbonImmutable $asOf, bool $persisted): self
    {
        $totals = [];

        foreach (CalculatedStatus::cases() as $case) {
            $totals[$case->value] = 0;
        }

        $transitions = [];

        foreach ($outcomes as $outcome) {
            $totals[$outcome->status()->value]++;

            if ($outcome->changed) {
                $transitions[] = $outcome;
            }
        }

        return new self(
            processed: count($outcomes),
            totals: $totals,
            transitions: $transitions,
            asOf: $asOf,
            persisted: $persisted,
        );
    }
}

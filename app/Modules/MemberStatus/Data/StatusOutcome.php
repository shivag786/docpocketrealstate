<?php

namespace App\Modules\MemberStatus\Data;

use App\Modules\MemberStatus\Enums\CalculatedStatus;

/**
 * What happened to one member during a recalculation.
 *
 * Returned whether or not anything was written, so a dry run can report
 * exactly what a real run would have done.
 */
final class StatusOutcome
{
    public function __construct(
        public readonly MemberRecord $member,
        public readonly ?CalculatedStatus $previousStatus,
        public readonly StatusResult $result,
        public readonly bool $changed,
        public readonly bool $persisted,
    ) {}

    public function status(): CalculatedStatus
    {
        return $this->result->status;
    }

    /** "ACTIVE -> PENDING", or just "PENDING" on a first calculation. */
    public function transition(): string
    {
        return $this->previousStatus === null
            ? $this->result->status->value
            : $this->previousStatus->value.' -> '.$this->result->status->value;
    }
}

<?php

namespace App\Modules\MemberStatus\Services;

use App\Modules\MemberStatus\Data\StatusOutcome;
use App\Modules\MemberStatus\Support\StatusConfig;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Status transitions, written to the application log (spec §28).
 *
 * Only transitions are logged. A run that confirms ten thousand unchanged
 * statuses writes nothing, because a log that records non-events is a log
 * nobody reads.
 */
class StatusTransitionLogger
{
    public function __construct(
        private readonly StatusConfig $config,
    ) {}

    public function transition(StatusOutcome $outcome, CarbonImmutable $effectiveAt): void
    {
        if (! $this->config->loggingEnabled) {
            return;
        }

        $channel = $this->config->logChannel === null
            ? Log::channel()
            : Log::channel($this->config->logChannel);

        $channel->info('Member status changed', [
            'member_id' => $outcome->member->id,
            'member_code' => $outcome->member->code,
            'old_status' => $outcome->previousStatus?->value,
            'new_status' => $outcome->result->status->value,
            'reason' => $outcome->result->reason,
            'last_activity_at' => $outcome->result->lastActivityAt?->toDateString(),
            'days_since_activity' => $outcome->result->daysSinceActivity,
            'date' => $effectiveAt->toDateString(),
        ]);
    }
}

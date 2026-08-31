<?php

namespace App\Modules\MemberStatus\Console\Commands;

use App\Modules\MemberStatus\Data\RecalculationSummary;
use App\Modules\MemberStatus\Data\StatusOutcome;
use App\Modules\MemberStatus\Enums\CalculatedStatus;
use App\Modules\MemberStatus\Services\StatusRecalculationService;
use App\Modules\MemberStatus\Support\Clock;
use App\Modules\MemberStatus\Support\StatusConfig;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * The scheduled status calculation (spec §23).
 *
 *      php artisan member-status:calculate
 *      php artisan member-status:calculate --member=101
 *      php artisan member-status:calculate --as-of=2026-06-30 --dry-run
 *
 * It reads members through the MemberProvider, resolves their latest qualifying
 * activity through the PropertySaleProvider, and writes only the module's three
 * tables. It touches no other application data — including `members.status`,
 * which it neither reads nor writes (spec §21).
 *
 * `--dry-run` performs the whole calculation and reports exactly what would
 * change without writing anything, which is how this is meant to be run against
 * live data for the first time.
 */
class CalculateMemberStatusCommand extends Command
{
    protected $signature = 'member-status:calculate
        {--member=* : Only these member ids (repeatable)}
        {--as-of= : Judge statuses as of this date (Y-m-d), defaults to today}
        {--chunk= : Members per batch, defaults to the configured chunk size}
        {--dry-run : Calculate and report without writing anything}
        {--transitions=25 : How many status changes to list}';

    protected $description = 'Recalculate the isolated member status snapshot (ACTIVE / PENDING / INACTIVE)';

    public function handle(StatusRecalculationService $recalculation, StatusConfig $config): int
    {
        try {
            $asOf = Clock::at($this->option('as-of') ?: null);
        } catch (Throwable) {
            $this->components->error('Could not read --as-of. Use a date like 2026-06-30.');

            return self::FAILURE;
        }

        $persist = ! $this->option('dry-run');
        $memberIds = array_values(array_filter((array) $this->option('member')));

        $this->components->info(sprintf(
            'Member status as of %s — active < %d days, pending < %d days, inactive from %d days.%s',
            $asOf->format('d M Y'),
            $config->activePeriodDays,
            $config->inactiveThresholdDays(),
            $config->inactiveThresholdDays(),
            $persist ? '' : ' DRY RUN — nothing will be written.',
        ));

        $summary = $memberIds === []
            ? $this->runAll($recalculation, $asOf, $persist)
            : $this->runFor($recalculation, $memberIds, $asOf, $persist);

        $this->report($summary);

        return self::SUCCESS;
    }

    private function runAll(
        StatusRecalculationService $recalculation,
        CarbonImmutable $asOf,
        bool $persist,
    ): RecalculationSummary {
        $chunk = $this->option('chunk') !== null ? (int) $this->option('chunk') : null;
        $processed = 0;

        return $recalculation->recalculateAll(
            asOf: $asOf,
            persist: $persist,
            chunkSize: $chunk,
            onChunk: function (RecalculationSummary $chunkSummary) use (&$processed) {
                $processed += $chunkSummary->processed;
                $this->output->write("\r  processed {$processed} members");
            },
        );
    }

    /**
     * @param  list<string>  $memberIds
     */
    private function runFor(
        StatusRecalculationService $recalculation,
        array $memberIds,
        CarbonImmutable $asOf,
        bool $persist,
    ): RecalculationSummary {
        $outcomes = $recalculation->recalculateMembers($memberIds, $asOf, $persist);

        $missing = count($memberIds) - count($outcomes);

        if ($missing > 0) {
            $this->components->warn("{$missing} of the given ids are not members and were skipped.");
        }

        return RecalculationSummary::fromOutcomes($outcomes, $asOf, $persist);
    }

    private function report(RecalculationSummary $summary): void
    {
        $this->output->write("\r");
        $this->newLine();

        $this->table(
            ['Status', 'Members'],
            array_map(
                fn (CalculatedStatus $status) => [$status->label(), number_format($summary->total($status))],
                CalculatedStatus::cases(),
            ),
        );

        $this->components->twoColumnDetail('Members processed', number_format($summary->processed));
        $this->components->twoColumnDetail('Status changes', number_format($summary->changed()));

        if ($summary->transitions === []) {
            $this->components->info('No status changed.');

            return;
        }

        $limit = max(0, (int) $this->option('transitions'));

        foreach (array_slice($summary->transitions, 0, $limit) as $outcome) {
            $this->components->twoColumnDetail(
                $this->describe($outcome),
                $outcome->transition(),
            );
        }

        $hidden = count($summary->transitions) - $limit;

        if ($hidden > 0) {
            $this->components->info("... and {$hidden} more. See member_status_history for the full list.");
        }
    }

    private function describe(StatusOutcome $outcome): string
    {
        $member = $outcome->member;

        return trim(($member->code ?? '#'.$member->id).' '.($member->name ?? ''));
    }
}

<?php

namespace App\Services;

use App\Enums\CalculationRunStatus;
use App\Enums\CalculationRunType;
use App\Enums\LedgerStatus;
use App\Enums\RewardType;
use App\Models\CalculationRun;
use App\Models\CompanyClubCalculationRun;
use App\Models\CompanyClubEligibilityPath;
use App\Models\CompanyClubReward;
use App\Models\CompanyClubSetting;
use App\Models\RewardLedger;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Company Club orchestration: preview, calculate, recalculate, history.
 *
 * HOW THIS ENGINE IS DRIVEN (client-confirmed 2026-08-19, amended 2026-09-01).
 *
 * THE MONTH MUST BE OVER BEFORE IT CAN BE CALCULATED. This is the 2026-09-01
 * amendment, and Company Club is the only engine that carries it, for a reason
 * that belongs to this engine alone: a Club reward is a DIVISION, not an
 * accumulation. A Direct reward only ever grows - a new sale adds a row and
 * moves nobody else's figure. A Club share is pool / eligible members, so one
 * new eligible member on the 25th cuts what everybody else receives. Committing
 * that mid-month publishes an amount that is certain to fall, and a member who
 * watched their share shrink has every reason to dispute it.
 *
 * Preview stays open every day of the month. It writes nothing and is labelled
 * an estimate, which is the honest way to answer "what is it looking like".
 *
 * The FIRST calculation of a month is always explicit. An admin previews, sees
 * the pool and the recipient list, and presses Calculate. Nothing writes a
 * financial row before that - the specification is unambiguous, and
 * CompanyClubCalculationService cannot write at all.
 *
 * EVERY CALCULATION AFTER THAT IS AUTOMATIC. Once an admin has committed to a
 * month, a later sale landing in it rebuilds the figures alongside the other four
 * engines. A month the admin has signed off on must never quietly fall out of
 * step with its sales. A month with no Company Club run is left completely alone
 * by sale entry - it does not spring into existence unattended.
 *
 * NOTHING IS EVER SILENTLY OVERWRITTEN. A rebuild supersedes the previous run and
 * clears its detail rows, but the snapshot in `company_club_calculation_runs`
 * survives with its own figures, code, timestamp and admin. That is what lets
 * every screen show what the previous calculation said and when - the admin is
 * never left guessing which figures they are looking at.
 *
 * The paid lock still wins over all of it: once a COMPANY CLUB reward in a
 * period is marked paid, this engine refuses to recalculate that period.
 *
 * That lock is Company Club's own (client-confirmed 2026-09-01). Paying a Team
 * Target does not freeze the Club, and confirming a Club share does not freeze
 * the Target - they are separate money, separately approved, and mixing their
 * payment states was making one engine hostage to the other.
 */
class CompanyClubService
{
    /**
     * The source of a Company Club ledger row.
     *
     * There is no single record behind the money - the source is the entire
     * month - so `source_id` is a constant 0 rather than a pretend foreign key.
     * The ledger's unique index is (member, reward_type, source_type, source_id,
     * period), which therefore reads as exactly one Company Club reward per
     * member per month, enforced by the database.
     */
    public const SOURCE_TYPE = 'company_club_pool';

    public const SOURCE_ID = 0;

    public function __construct(
        private readonly CompanyClubCalculationService $calculator,
        private readonly CompanyClubTreeService $tree,
        private readonly CalculationRunService $runs,
    ) {}

    // -----------------------------------------------------------------
    // Read side - writes nothing, ever
    // -----------------------------------------------------------------

    /**
     * What the month would produce, plus the context an admin needs to judge it.
     *
     * @return array<string, mixed>
     */
    public function preview(string $period): array
    {
        $this->runs->assertValidPeriod($period);

        $result = $this->calculator->compute($period);
        $lastRun = $this->latestRun($period);

        return $result + [
            'members' => $this->calculator->membersFor($result),
            'last_run' => $lastRun,
            'previous_runs' => $this->history($period, 5),
            'calculated' => $lastRun !== null,
            'needs_recalculation' => $this->needsRecalculation($period),
            'locked' => $this->runs->periodIsPaid($period, CalculationRunType::CompanyClub),
            'month_is_over' => $this->runs->periodHasEnded($period),
            'settings' => CompanyClubSetting::current(),
        ];
    }

    /**
     * The live run for a period, or null when it has never been calculated.
     */
    public function latestRun(string $period): ?CompanyClubCalculationRun
    {
        return CompanyClubCalculationRun::query()
            ->forPeriod($period)
            ->completed()
            ->with('initiatedBy:id,name')
            ->latest('id')
            ->first();
    }

    /**
     * Every calculation ever made for a period, newest first, superseded ones
     * included. This is what "show the previous date of calculation" needs.
     *
     * @return Collection<int, CompanyClubCalculationRun>
     */
    public function history(string $period, ?int $limit = null)
    {
        return CompanyClubCalculationRun::query()
            ->forPeriod($period)
            ->with('initiatedBy:id,name')
            ->latest('id')
            ->when($limit !== null, fn ($q) => $q->limit($limit))
            ->get();
    }

    /**
     * Whether the stored figures still match what the sales say now.
     *
     * Compared on eligible Sq.Ft., which is this engine's own input. It cannot
     * be compared against the Direct run: Direct counts inactive sellers and
     * Company Club does not, so the two legitimately differ.
     */
    public function needsRecalculation(string $period): bool
    {
        $run = $this->latestRun($period);

        if ($run === null) {
            return false; // never calculated is not the same as out of date
        }

        $network = $this->tree->sponsorMap();
        $liveSqft = Money::sum($this->calculator->eligibleSellerTotals($period, $network)->values());

        return Money::compare($liveSqft, (string) $run->total_sqft) !== 0;
    }

    // -----------------------------------------------------------------
    // Write side
    // -----------------------------------------------------------------

    /**
     * First calculation of a period. Refused if one already exists, and refused
     * while the month is still running.
     *
     * @throws RuntimeException when the month has not finished
     */
    public function calculate(string $period, User $initiatedBy): CompanyClubCalculationRun
    {
        $this->assertPeriodIsComplete($period);

        $run = $this->runs->execute(
            $period,
            CalculationRunType::CompanyClub,
            $initiatedBy,
            fn (CalculationRun $run) => $this->post($period, $run, $initiatedBy, false),
        );

        return $this->clubRunFor($run);
    }

    /**
     * Recalculate a period from the sales as they stand now.
     *
     * Supersedes the previous run rather than erasing it.
     */
    public function recalculate(string $period, User $initiatedBy, bool $automatic = false): CompanyClubCalculationRun
    {
        $run = $this->runs->execute(
            $period,
            CalculationRunType::CompanyClub,
            $initiatedBy,
            fn (CalculationRun $run) => $this->post($period, $run, $initiatedBy, $automatic),
            fn (string $p) => $this->clearPeriod($p),
        );

        return $this->clubRunFor($run);
    }

    /**
     * The automatic path, called when a sale lands in a month.
     *
     * Deliberately does NOTHING for a month that has never been calculated: the
     * first run must be an admin's explicit decision. Returns null in that case
     * so the caller can tell "nothing to do" from "rebuilt".
     */
    public function recalculateIfCalculated(string $period, User $initiatedBy): ?CompanyClubCalculationRun
    {
        if ($this->latestRun($period) === null) {
            return null;
        }

        // A paid Club month returns null rather than throwing. This is the
        // AUTOMATIC path, called from inside the whole-month rebuild: raising
        // here would roll back the four engines that are perfectly free to
        // recalculate, which is precisely the coupling the per-engine lock
        // exists to remove. The manual `recalculate()` still throws, because
        // there an admin pressed a button and is owed the reason.
        if ($this->runs->periodIsPaid($period, CalculationRunType::CompanyClub)) {
            return null;
        }

        return $this->recalculate($period, $initiatedBy, true);
    }

    /**
     * Whether the month has finished and may therefore be committed.
     */
    public function periodIsComplete(string $period): bool
    {
        return $this->runs->periodHasEnded($period);
    }

    /**
     * Why the Calculate button is unavailable, or null when it is available.
     *
     * Preview is deliberately NOT gated by this - it writes nothing, and an
     * admin watching the month build up is exactly who it is for.
     */
    public function calculationBlockedReason(string $period): ?string
    {
        if ($this->periodIsComplete($period)) {
            return null;
        }

        return sprintf(
            '%s has not finished. A Company Club share is the pool divided between '
            .'the eligible members, so every sale still to come this month changes '
            .'what each member receives. The month can be calculated from %s.',
            $period,
            Carbon::parse($period.'-01')->addMonth()->format('d M Y'),
        );
    }

    private function assertPeriodIsComplete(string $period): void
    {
        $reason = $this->calculationBlockedReason($period);

        if ($reason !== null) {
            throw new RuntimeException($reason);
        }
    }

    /**
     * Write the month.
     *
     * The figures come from CompanyClubCalculationService, unchanged - the same
     * call Preview made. Nothing is recomputed differently here, which is what
     * makes the preview an honest promise of the outcome.
     *
     * @return array{records: int, sqft: string, amount: string}
     */
    private function post(string $period, CalculationRun $run, User $initiatedBy, bool $automatic): array
    {
        $result = $this->calculator->compute($period);

        $clubRun = CompanyClubCalculationRun::create([
            'run_code' => $this->nextRunCode($period),
            'period' => $period,
            'total_sqft' => $result['total_sqft'],
            // Frozen: editing the settings later cannot rewrite this figure.
            'rate' => $result['rate'],
            'pool_amount' => $result['pool_amount'],
            'eligible_count' => $result['eligible_count'],
            'equal_share' => $result['equal_share'],
            'distributed_amount' => $result['distributed_amount'],
            'residual_amount' => $result['residual_amount'],
            'seller_count' => $result['seller_count'],
            'status' => CalculationRunStatus::Completed,
            'calculation_run_id' => $run->id,
            'initiated_by' => $initiatedBy->id,
            'automatic' => $automatic,
        ]);

        $now = now();
        $rewardRows = [];
        $ledgerRows = [];
        $pathRows = [];

        foreach ($result['recipients'] as $recipient) {
            $rewardRows[] = [
                'company_club_run_id' => $clubRun->id,
                'member_id' => $recipient['member_id'],
                'amount' => $recipient['amount'],
                'eligibility_path_count' => $recipient['path_count'],
                'best_level' => $recipient['best_level'],
                'status' => LedgerStatus::Posted->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $ledgerRows[] = [
                'member_id' => $recipient['member_id'],
                'reward_type' => RewardType::CompanyClub->value,
                'source_type' => self::SOURCE_TYPE,
                'source_id' => self::SOURCE_ID,
                'period' => $period,
                // The whole month's eligible Sq.Ft., which is what produced the
                // pool this share came out of.
                'sqft' => $result['total_sqft'],
                'rate' => $result['rate'],
                'amount' => $recipient['amount'],
                'status' => LedgerStatus::Posted->value,
                'calculation_run_id' => $run->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach ($result['paths'] as $path) {
            $pathRows[] = [
                'company_club_run_id' => $clubRun->id,
                'sale_member_id' => $path['sale_member_id'],
                'eligible_member_id' => $path['eligible_member_id'],
                'upline_level' => $path['upline_level'],
                'chain_depth' => $path['chain_depth'],
                'sale_member_sqft' => $path['sale_member_sqft'],
                'path_snapshot' => json_encode($path['path_snapshot']),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rewardRows, 500) as $chunk) {
            CompanyClubReward::insert($chunk);
        }

        foreach (array_chunk($ledgerRows, 500) as $chunk) {
            RewardLedger::insert($chunk);
        }

        foreach (array_chunk($pathRows, 500) as $chunk) {
            CompanyClubEligibilityPath::insert($chunk);
        }

        return [
            'records' => $result['eligible_count'],
            'sqft' => $result['total_sqft'],
            'amount' => $result['distributed_amount'],
        ];
    }

    /**
     * Throw away a period's Company Club detail, keeping its history.
     *
     * The reward and path rows go, because they describe amounts that are being
     * replaced. The run snapshots STAY, marked superseded - they are the record
     * of what was calculated, by whom and when, which is the one thing a rebuild
     * must not destroy.
     */
    private function clearPeriod(string $period): void
    {
        $runIds = CompanyClubCalculationRun::query()
            ->forPeriod($period)
            ->pluck('id');

        if ($runIds->isNotEmpty()) {
            CompanyClubReward::whereIn('company_club_run_id', $runIds)->delete();
            CompanyClubEligibilityPath::whereIn('company_club_run_id', $runIds)->delete();
        }

        CompanyClubCalculationRun::query()
            ->forPeriod($period)
            ->completed()
            ->update(['status' => CalculationRunStatus::Superseded->value]);
    }

    /**
     * The next run code for a period: CC-2026-08-0001, CC-2026-08-0002, ...
     *
     * Sequential within the period and never reused, so a code always identifies
     * one calculation for the rest of time.
     */
    public function nextRunCode(string $period): string
    {
        $sequence = CompanyClubCalculationRun::query()
            ->forPeriod($period)
            ->lockForUpdate()
            ->count() + 1;

        return sprintf('CC-%s-%04d', $period, $sequence);
    }

    private function clubRunFor(CalculationRun $run): CompanyClubCalculationRun
    {
        return CompanyClubCalculationRun::query()
            ->where('calculation_run_id', $run->id)
            ->latest('id')
            ->firstOrFail();
    }

    // -----------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------

    public function settings(): CompanyClubSetting
    {
        return CompanyClubSetting::current();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateSettings(array $data): CompanyClubSetting
    {
        return DB::transaction(function () use ($data) {
            $settings = CompanyClubSetting::current();
            $settings->update($data);

            return $settings->refresh();
        });
    }
}

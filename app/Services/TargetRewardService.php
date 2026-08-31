<?php

namespace App\Services;

use App\Enums\CalculationRunType;
use App\Enums\LedgerStatus;
use App\Enums\RewardType;
use App\Enums\TargetLevel;
use App\Models\CalculationRun;
use App\Models\Member;
use App\Models\RegistrySale;
use App\Models\TargetCalculation;
use App\Models\TeamCalculation;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The three team targets — 5,000 / 1 month, 10,000 / 2 months, 35,000 / 3 months,
 * winning a fixed ₹50,000 / ₹200,000 / ₹700,000 (client-confirmed 2026-08-25).
 *
 * Rules implemented (docs/02_BUSINESS_RULES.md §3.1, confirmed 2026-08-17 and
 * extended for Targets 2 and 3 on 2026-08-18):
 *
 *  1. A window is always whole calendar months, 1st to last day, and a
 *     multi-month window ACCUMULATES across the months inside it. Joining date
 *     is never consulted and a threshold is never pro-rated.
 *
 *  2. THE PRIZE IS FIXED. Reaching the threshold wins the whole prize and
 *     nothing more: a team doing 12,000 against Target 2 wins the same ₹200,000
 *     as one doing exactly 10,000. The surplus is discarded and does not carry
 *     into Target 3, which starts from zero.
 *
 *  3. PAID AS SOON AS REACHED. The window is a deadline, not a wait: a member
 *     who does the whole 10,000 in the first month of a two-month window
 *     achieves it that month and their next target opens the month after. The
 *     unused month is not held open.
 *
 *  4. A MISS RESETS TO ZERO AND OPENS A FRESH BLOCK. Windows never overlap —
 *     every month belongs to exactly one attempt, which is what the confirmed
 *     "never a rolling window" means once a target spans more than one month.
 *     Retries are unlimited and carry no penalty.
 *
 *  5. ONE ACHIEVEMENT PER MEMBER PER TARGET, EVER, and one target at a time.
 *     Achieving Target 3 ends the ladder; that member is never measured again.
 *
 *  6. MEMBER STATUS IS NOT CONSULTED. No compression, unlike the upline engine.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS ENGINE REPLAYS HISTORY INSTEAD OF READING ITS OWN PREVIOUS ROWS
 *
 * A Target 1 verdict was a statement about one month, so it could be computed
 * from that month alone. A Target 2 verdict cannot: it depends on which target
 * the member is on, when their current window opened, and what they have
 * accumulated inside it — all of which are consequences of every month before
 * it.
 *
 * The obvious approach is to read the previous period's stored verdict and carry
 * it forward. This engine deliberately does not. Sales can be BACK-DATED, and
 * the moment one lands in an earlier month every later verdict derived from it
 * is wrong while still looking authoritative. Instead, `replay()` rebuilds every
 * member's whole progression from `team_calculations` — the same figures the
 * Team Sales report shows — and keeps only the verdict for the period being
 * written. The stored rows are therefore an output of the ladder, never an input
 * to it, and cannot drift from the sales.
 *
 * The cost is that rebuilding one month invalidates the months after it.
 * `PeriodRecalculationService` closes that loop by cascading Target forward over
 * every later period.
 */
class TargetRewardService
{
    private const MAX_DEPTH = 100;

    /** Refuse to build a tree view larger than a page can honestly render. */
    private const MAX_TREE_NODES = 2000;

    public function __construct(
        private readonly CalculationRunService $runs,
    ) {}

    public function calculate(string $period, User $initiatedBy): CalculationRun
    {
        return $this->runs->execute(
            $period,
            CalculationRunType::Target,
            $initiatedBy,
            fn (CalculationRun $run) => $this->post($period, $run),
        );
    }

    /**
     * Recalculate the period's verdicts from the sales as they stand now.
     *
     * Deleting this period's rows also releases any achievement recorded in it,
     * because the once-ever guard lives in those rows. That is deliberate: while
     * a month is still provisional an achievement can appear and disappear as
     * sales arrive. Once a target reward is PAID the period locks and this
     * refuses to run, so a paid achievement can never be taken away.
     */
    public function recalculate(string $period, User $initiatedBy): CalculationRun
    {
        return $this->runs->execute(
            $period,
            CalculationRunType::Target,
            $initiatedBy,
            fn (CalculationRun $run) => $this->post($period, $run),
            fn (string $p) => TargetCalculation::where('period', $p)->delete(),
        );
    }

    /**
     * @return array{records: int, sqft: string, amount: string}
     */
    private function post(string $period, CalculationRun $run): array
    {
        $this->assertTeamSalesCalculated($period);

        $verdicts = $this->replay($period);

        $rows = [];
        $paidSqft = Money::zero();
        $paidAmount = Money::zero();

        foreach ($verdicts as $verdict) {
            $rows[] = [
                'member_id' => $verdict['member_id'],
                'period' => $period,
                'target_level' => $verdict['level']->value,
                'window_start' => $verdict['window_start'],
                'window_end' => $verdict['window_end'],
                'window_months' => $verdict['level']->months(),
                'target_sqft' => $verdict['target_sqft'],
                'rate' => $verdict['rate'],
                'achieved_sqft' => $verdict['month_sqft'],
                'cumulative_sqft' => $verdict['cumulative_sqft'],
                'own_sqft' => $verdict['own_sqft'],
                'achieved' => $verdict['achieved'],
                'shortfall_sqft' => $verdict['shortfall_sqft'],
                'reward_amount' => $verdict['reward_amount'],
                // The once-ever guard. NULL unless this row IS the achievement,
                // so misses and in-progress rows never collide.
                'achieved_level' => $verdict['achieved'] ? $verdict['level']->value : null,
                'calculation_run_id' => $run->id,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if ($verdict['achieved']) {
                $paidSqft = Money::add($paidSqft, $verdict['target_sqft']);
                $paidAmount = Money::add($paidAmount, $verdict['reward_amount']);
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            TargetCalculation::insert($chunk);
        }

        $this->postLedger($run, $period);

        return [
            // Everyone measured is recorded — achievers, members still inside an
            // open window, and members whose window just closed short.
            'records' => count($rows),
            // The Sq.Ft. actually PAID ON, which is the threshold once per
            // achiever, not the Sq.Ft. they sold. total_sqft × rate therefore
            // reconciles to total_amount only while all achievers share a rate,
            // which they do: ₹30 across all three targets.
            'sqft' => $paidSqft,
            'amount' => $paidAmount,
        ];
    }

    /**
     * Replay every member's ladder and return the verdict for one period.
     *
     * The walk covers every calendar month from the first month with team
     * figures up to `$upTo` INCLUSIVE — including months in which nothing was
     * sold, because an empty month still consumes one month of an open window.
     *
     * @return array<int, array<string, mixed>> verdicts for `$upTo`, in no order
     */
    private function replay(string $upTo): array
    {
        $totals = $this->teamTotalsUpTo($upTo);

        if ($totals === []) {
            return [];
        }

        $months = $this->monthsBetween($this->earliestPeriod($totals), $upTo);
        $verdicts = [];

        foreach ($totals as $memberId => $byPeriod) {
            $verdict = $this->replayMember($memberId, $byPeriod, $months, $upTo);

            if ($verdict !== null) {
                $verdicts[$memberId] = $verdict;
            }
        }

        return $verdicts;
    }

    /**
     * One member's progression, month by month.
     *
     * @param  array<string, array{team: string, own: string}>  $byPeriod
     * @param  array<int, string>  $months
     * @return array<string, mixed>|null null when the member is not measured in `$upTo`
     */
    private function replayMember(int $memberId, array $byPeriod, array $months, string $upTo): ?array
    {
        $level = TargetLevel::One;
        $windowStart = null;
        $cumulative = Money::zero();
        $finished = false;
        $result = null;

        foreach ($months as $month) {
            if ($finished) {
                break;
            }

            // A window opens on the first month after the previous one closed —
            // whether it closed by achievement or by running out of months.
            if ($windowStart === null) {
                $windowStart = $month;
                $cumulative = Money::zero();
            }

            $windowEnd = $this->addMonths($windowStart, $level->months() - 1);

            $monthSqft = Money::of($byPeriod[$month]['team'] ?? '0');
            $ownSqft = Money::of($byPeriod[$month]['own'] ?? '0');
            $cumulative = Money::add($cumulative, $monthSqft);

            $threshold = $level->sqft();
            $achieved = Money::compare($cumulative, $threshold) >= 0;
            $windowClosing = $month === $windowEnd;

            if ($month === $upTo && $this->isRecorded($level, $byPeriod, $month)) {
                $result = [
                    'member_id' => $memberId,
                    'level' => $level,
                    'window_start' => $windowStart,
                    'window_end' => $windowEnd,
                    'target_sqft' => $threshold,
                    'rate' => $level->rate(),
                    'month_sqft' => $monthSqft,
                    'own_sqft' => $ownSqft,
                    'cumulative_sqft' => $cumulative,
                    'achieved' => $achieved,
                    // Never negative: a surplus is discarded, not recorded as a
                    // negative shortfall.
                    'shortfall_sqft' => $achieved
                        ? Money::zero()
                        : Money::subtract($threshold, $cumulative),
                    'reward_amount' => $achieved ? $level->reward() : Money::zero(),
                ];
            }

            if ($achieved) {
                $next = $level->next();

                if ($next === null) {
                    // Target 3 done. The ladder ends and so does measurement.
                    $finished = true;
                } else {
                    $level = $next;
                }

                $windowStart = null;
            } elseif ($windowClosing) {
                $windowStart = null;
            }
        }

        return $result;
    }

    /**
     * Whether this month produces a stored row for this member.
     *
     * A member on Target 1 is recorded only in months they actually have team
     * figures for — a one-month window with no sales is not an attempt anybody
     * needs to read about, and recording it would put every member on the "not
     * reached" page every month.
     *
     * Inside a MULTI-MONTH window the opposite is true: a quiet month is part of
     * an attempt in progress, and hiding it would make the accumulated total
     * appear from nowhere when the window closes.
     *
     * @param  array<string, array{team: string, own: string}>  $byPeriod
     */
    private function isRecorded(TargetLevel $level, array $byPeriod, string $month): bool
    {
        return $level->months() > 1 || isset($byPeriod[$month]);
    }

    /**
     * Write one ledger row per achievement in the period.
     *
     * The rows are re-read from the table just written so each ledger entry can
     * carry its target_calculation id as `source_id` — that is what makes every
     * reward traceable back to the exact verdict that produced it.
     *
     * A member can achieve at most one target in a month: achieving closes the
     * window and the next target's window opens the FOLLOWING month. So one
     * ledger row per member per period, which is what the unique index on
     * (member, type, source_type, source_id, period) assumes.
     */
    private function postLedger(CalculationRun $run, string $period): void
    {
        $achievements = TargetCalculation::query()
            ->where('calculation_run_id', $run->id)
            ->achieved()
            ->get(['id', 'member_id', 'target_sqft', 'rate', 'reward_amount']);

        $ledger = $achievements->map(fn (TargetCalculation $row) => [
            'member_id' => $row->member_id,
            'reward_type' => RewardType::Target->value,
            'source_type' => 'target_calculation',
            'source_id' => $row->id,
            'period' => $period,
            // The THRESHOLD, not what they sold. sqft × rate = amount holds on
            // every row, which is what reconciliation in Phase 13 relies on.
            'sqft' => $row->target_sqft,
            'rate' => $row->rate,
            'amount' => $row->reward_amount,
            'status' => LedgerStatus::Posted->value,
            'calculation_run_id' => $run->id,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        foreach (array_chunk($ledger, 500) as $chunk) {
            DB::table('reward_ledger')->insert($chunk);
        }
    }

    /**
     * Every member's team figures, by period, up to and including `$upTo`.
     *
     * @return array<int, array<string, array{team: string, own: string}>>
     */
    private function teamTotalsUpTo(string $upTo): array
    {
        $rows = TeamCalculation::query()
            ->where('period', '<=', $upTo)
            ->orderBy('period')
            ->get(['leader_id', 'period', 'own_sqft', 'total_team_sqft']);

        $totals = [];

        foreach ($rows as $row) {
            $totals[(int) $row->leader_id][$row->period] = [
                'team' => Money::of($row->total_team_sqft),
                'own' => Money::of($row->own_sqft),
            ];
        }

        return $totals;
    }

    /**
     * @param  array<int, array<string, mixed>>  $totals
     */
    private function earliestPeriod(array $totals): string
    {
        $earliest = null;

        foreach ($totals as $byPeriod) {
            foreach (array_keys($byPeriod) as $period) {
                if ($earliest === null || $period < $earliest) {
                    $earliest = $period;
                }
            }
        }

        return $earliest ?? now()->format('Y-m');
    }

    /**
     * Every calendar month from `$from` to `$to` inclusive.
     *
     * @return array<int, string>
     */
    private function monthsBetween(string $from, string $to): array
    {
        $months = [];
        $cursor = $from;

        // Guard against a malformed range producing an unbounded loop.
        while ($cursor <= $to && count($months) < 1200) {
            $months[] = $cursor;
            $cursor = $this->addMonths($cursor, 1);
        }

        return $months;
    }

    private function addMonths(string $period, int $months): string
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $period.'-01')
            ->addMonths($months)
            ->format('Y-m');
    }

    /**
     * Team Sales is the input to this engine, so it has to exist first — and for
     * every month the replay walks, not only the one being written.
     *
     * A multi-month window reaches back into earlier periods, so an earlier
     * month that has sales but no Team Sales run would silently contribute zero
     * and could turn an achievement into a miss. Months with no sales need no
     * run: they legitimately contribute nothing.
     */
    private function assertTeamSalesCalculated(string $period): void
    {
        $missing = $this->periodsMissingTeamSales($period);

        if ($missing === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Team Sales has not been calculated for %s. Targets accumulate across months, '
            .'so every month with sales up to %s must be rolled up before a verdict can be '
            .'trusted — otherwise a month would silently count as zero.',
            implode(', ', $missing),
            $period,
        ));
    }

    /**
     * Months at or before `$period` that have approved sales but no Team Sales run.
     *
     * @return array<int, string>
     */
    private function periodsMissingTeamSales(string $period): array
    {
        $withSales = RegistrySale::query()
            ->approved()
            ->selectRaw("DATE_FORMAT(registry_date, '%Y-%m') as period")
            ->groupBy('period')
            ->having('period', '<=', $period)
            ->orderBy('period')
            ->pluck('period');

        $missing = [];

        foreach ($withSales as $candidate) {
            if ($this->runs->completedRun($candidate, CalculationRunType::TeamSales) === null) {
                $missing[] = $candidate;
            }
        }

        return $missing;
    }

    // -----------------------------------------------------------------
    // Read-side helpers (no writes)
    // -----------------------------------------------------------------

    /**
     * What a period would produce, without writing anything.
     *
     * @return array{
     *     measured: int, achieved: int, in_progress: int, missed: int,
     *     total_amount: string, team_sales_ready: bool,
     *     by_level: array<int, array{measured: int, achieved: int, in_progress: int, missed: int, amount: string}>
     * }
     */
    public function preview(string $period): array
    {
        $this->runs->assertValidPeriod($period);

        $ready = $this->periodsMissingTeamSales($period) === [];

        $byLevel = [];

        foreach (TargetLevel::all() as $level) {
            $byLevel[$level->value] = [
                'measured' => 0,
                'achieved' => 0,
                'in_progress' => 0,
                'missed' => 0,
                'amount' => Money::zero(),
            ];
        }

        $measured = 0;
        $achieved = 0;
        $inProgress = 0;
        $total = Money::zero();

        // Without every month rolled up the replay would read missing months as
        // zero and report verdicts that the engine itself would refuse to write.
        foreach ($ready ? $this->replay($period) : [] as $verdict) {
            $level = $verdict['level']->value;
            $isOpen = ! $verdict['achieved'] && $period !== $verdict['window_end'];

            $measured++;
            $byLevel[$level]['measured']++;

            if ($verdict['achieved']) {
                $achieved++;
                $byLevel[$level]['achieved']++;
                $byLevel[$level]['amount'] = Money::add($byLevel[$level]['amount'], $verdict['reward_amount']);
                $total = Money::add($total, $verdict['reward_amount']);
            } elseif ($isOpen) {
                $inProgress++;
                $byLevel[$level]['in_progress']++;
            } else {
                $byLevel[$level]['missed']++;
            }
        }

        return [
            'measured' => $measured,
            'achieved' => $achieved,
            'in_progress' => $inProgress,
            'missed' => $measured - $achieved - $inProgress,
            'total_amount' => $total,
            'team_sales_ready' => $ready,
            'by_level' => $byLevel,
        ];
    }

    /**
     * Every target verdict for one member, newest period first.
     *
     * @return Collection<int, TargetCalculation>
     */
    public function forMember(Member $member): Collection
    {
        return TargetCalculation::query()
            ->where('member_id', $member->id)
            ->orderByDesc('period')
            ->get();
    }

    /**
     * The months of one verdict's window, each with the team figure it added.
     *
     * This is what makes a multi-month verdict readable: a Target 2 row saying
     * "11,200 of 10,000" means nothing until you can see it was 4,000 in the
     * first month and 7,200 in the second.
     *
     * @return array<int, array{period: string, sqft: string, running: string, is_current: bool}>
     */
    public function windowMonths(TargetCalculation $calculation): array
    {
        $months = $this->monthsBetween($calculation->window_start, $calculation->window_end);

        $figures = TeamCalculation::query()
            ->where('leader_id', $calculation->member_id)
            ->whereIn('period', $months)
            ->pluck('total_team_sqft', 'period');

        $out = [];
        $running = Money::zero();

        foreach ($months as $month) {
            // Months after the one being reported have not happened yet as far
            // as this verdict is concerned, so they contribute nothing to it.
            $counted = $month <= $calculation->period;
            $sqft = $counted ? Money::of($figures[$month] ?? '0') : Money::zero();

            if ($counted) {
                $running = Money::add($running, $sqft);
            }

            $out[] = [
                'period' => $month,
                'sqft' => $sqft,
                'running' => $running,
                'is_current' => $month === $calculation->period,
                'counted' => $counted,
            ];
        }

        return $out;
    }

    /**
     * The member's team, as a tree, with each node's Sq.Ft. for the period.
     *
     * This is the explanation surface behind a target verdict: the root's
     * subtree total IS the figure that was measured for this month, so the tree
     * can be read as the working behind the number. On a multi-month target it
     * explains THIS month's contribution — `windowMonths()` explains the rest.
     *
     * Branches that sold nothing in the period are PRUNED — they contributed
     * nothing to the figure being explained, and rendering hundreds of zero rows
     * would bury the ones that matter. The count of pruned members is returned
     * so the omission is stated rather than hidden.
     *
     * @return array{
     *     root: array<string, mixed>|null, total_sqft: string,
     *     contributors: int, pruned: int, branch_size: int, truncated: bool
     * }
     */
    public function contributionTree(Member $member, string $period): array
    {
        $rows = $this->branchWithSales($member, $period);

        if (count($rows) > self::MAX_TREE_NODES) {
            return [
                'root' => null,
                'total_sqft' => Money::zero(),
                'contributors' => 0,
                'pruned' => 0,
                'branch_size' => count($rows),
                'truncated' => true,
            ];
        }

        /** @var array<int, array<string, mixed>> $nodes */
        $nodes = [];

        foreach ($rows as $row) {
            $nodes[(int) $row->id] = [
                'id' => (int) $row->id,
                'sponsor_id' => $row->sponsor_id === null ? null : (int) $row->sponsor_id,
                'depth' => (int) $row->depth,
                'member_code' => $row->member_code,
                'name' => $row->name,
                'status' => $row->status,
                'own_sqft' => Money::of($row->own_sqft),
                'sales' => (int) $row->sales,
                'team_sqft' => Money::zero(),
                'children' => [],
            ];
        }

        // Deepest first, so every child's subtree total is final before its
        // parent reads it. One pass, no recursion.
        $ordered = $nodes;
        uasort($ordered, fn (array $a, array $b) => $b['depth'] <=> $a['depth']);

        foreach ($ordered as $id => $node) {
            $nodes[$id]['team_sqft'] = Money::add($nodes[$id]['team_sqft'], $nodes[$id]['own_sqft']);

            $parentId = $nodes[$id]['sponsor_id'];

            if ($parentId !== null && isset($nodes[$parentId])) {
                $nodes[$parentId]['team_sqft'] = Money::add(
                    $nodes[$parentId]['team_sqft'],
                    $nodes[$id]['team_sqft'],
                );
            }
        }

        $contributors = 0;
        $pruned = 0;

        foreach ($nodes as $id => $node) {
            if (Money::isPositive($node['own_sqft'])) {
                $contributors++;
            }

            // The root is the subject of the page and is always rendered, even
            // when its whole branch sold nothing.
            if ($id !== $member->id && Money::isZero($node['team_sqft'])) {
                $pruned++;
            }
        }

        // Index children by parent once. Rebuilding this per node would make
        // assembly quadratic on a wide network.
        $childrenOf = [];

        foreach ($nodes as $node) {
            if ($node['sponsor_id'] !== null) {
                $childrenOf[$node['sponsor_id']][] = $node['id'];
            }
        }

        return [
            'root' => $this->assemble($member->id, $nodes, $childrenOf),
            'total_sqft' => $nodes[$member->id]['team_sqft'] ?? Money::zero(),
            'contributors' => $contributors,
            'pruned' => $pruned,
            'branch_size' => count($nodes),
            'truncated' => false,
        ];
    }

    /**
     * Build one node and its contributing children, subtree totals already final.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<int, array<int, int>>  $childrenOf
     * @return array<string, mixed>|null
     */
    private function assemble(int $id, array $nodes, array $childrenOf): ?array
    {
        if (! isset($nodes[$id])) {
            return null;
        }

        $node = $nodes[$id];
        $children = [];

        foreach ($childrenOf[$id] ?? [] as $childId) {
            // A branch that sold nothing this period explains nothing about the
            // figure this page exists to explain.
            if (Money::isZero($nodes[$childId]['team_sqft'])) {
                continue;
            }

            $children[] = $this->assemble($childId, $nodes, $childrenOf);
        }

        usort(
            $children,
            fn (array $a, array $b) => Money::compare($b['team_sqft'], $a['team_sqft'])
        );

        $node['children'] = $children;
        unset($node['sponsor_id']);

        return $node;
    }

    /**
     * The member's whole branch with each member's approved Sq.Ft. for the period.
     *
     * LEFT JOIN, not INNER: members who sold nothing are still needed, because
     * one of their descendants may have sold and the tree has to connect them.
     *
     * @return array<int, object>
     */
    private function branchWithSales(Member $member, string $period): array
    {
        [$year, $month] = array_map('intval', explode('-', $period));

        return DB::select(
            <<<'SQL'
            WITH RECURSIVE branch AS (
                SELECT id, sponsor_id, 0 AS depth
                FROM members
                WHERE id = ? AND deleted_at IS NULL

                UNION ALL

                SELECT m.id, m.sponsor_id, b.depth + 1
                FROM members m
                INNER JOIN branch b ON m.sponsor_id = b.id
                WHERE m.deleted_at IS NULL AND b.depth < ?
            )
            SELECT
                b.id,
                b.sponsor_id,
                b.depth,
                mem.member_code,
                mem.name,
                mem.status,
                mem.sequence_number,
                COALESCE(SUM(s.sqft), 0) AS own_sqft,
                COUNT(s.id) AS sales
            FROM branch b
            INNER JOIN members mem ON mem.id = b.id
            LEFT JOIN registry_sales s
                ON s.member_id = b.id
               AND s.status = 'approved'
               AND YEAR(s.registry_date) = ?
               AND MONTH(s.registry_date) = ?
            GROUP BY b.id, b.sponsor_id, b.depth, mem.member_code, mem.name,
                     mem.status, mem.sequence_number
            ORDER BY b.depth, mem.sequence_number
            SQL,
            [$member->id, self::MAX_DEPTH, $year, $month]
        );
    }
}

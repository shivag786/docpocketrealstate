<?php

namespace App\Services;

use App\Enums\CalculationRunType;
use App\Enums\LedgerStatus;
use App\Enums\RewardType;
use App\Models\CalculationRun;
use App\Models\Member;
use App\Models\TargetCalculation;
use App\Models\TeamCalculation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Target 1 engine — one calendar month, 5,000 Sq.Ft., ₹150,000.
 *
 * Client-confirmed 2026-08-17 (docs/02_BUSINESS_RULES.md §3.1). The rules this
 * class implements, and the ones it deliberately does NOT:
 *
 *  1. PERIOD IS A CALENDAR MONTH, 1st to last day. Never a rolling window. A
 *     member who joined mid-month is measured against the same month-end and the
 *     threshold is NOT pro-rated — so joining date is not consulted at all here.
 *     That absence is the rule, not an oversight.
 *
 *  2. REWARD IS FIXED AT THE THRESHOLD. A team doing 7,000 is paid on 5,000:
 *     5,000 × ₹30 = ₹150,000, never 7,000 × ₹30. The surplus is discarded and
 *     does not carry into Target 2, which starts from zero.
 *
 *  3. EVERY MEMBER IS MEASURED, not only Team Leaders. A member with no downline
 *     who sells 5,000 on their own achieves it. This follows for free from
 *     reading team_calculations, which carries a row per member with any sales.
 *
 *  4. ONE ACHIEVEMENT PER MEMBER, EVER. Failure repeats the target next month
 *     with unlimited retries and no penalty; achievement pays once and moves the
 *     member permanently on to Target 2. Enforced twice — the engine skips
 *     members who have already achieved, and a unique index on
 *     (member_id, achieved_level) makes a second achievement physically
 *     impossible even if the guard were bypassed or periods were calculated out
 *     of order.
 *
 *  5. MEMBER STATUS IS NOT CONSULTED. Active/Inactive has no bearing on being
 *     measured or paid. Unlike the upline engine, there is no compression here.
 *
 * The measured figure comes from `team_calculations` rather than being
 * recomputed, so the number a target was judged against is the same number the
 * Team Sales report showed, frozen by that run. Team Sales must therefore be
 * calculated for the period first; this engine refuses to guess.
 */
class TargetRewardService
{
    /** Phase 8 delivers Target 1 only. Targets 2 and 3 are Phases 9 and 10. */
    public const LEVEL = 1;

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
     *
     * Chronology note: an achievement already held in a DIFFERENT period still
     * blocks a new one here. Back-dating a sale into an earlier month therefore
     * leaves the achievement attributed to the month it was first earned in
     * rather than moving it. Nobody is ever paid twice, which is the property
     * that matters.
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

        $targetSqft = $this->targetSqft();
        $rate = $this->rate();
        $reward = Money::multiply($targetSqft, $rate);

        // Members who have already achieved this target are no longer measured
        // against it — they are on Target 2 from that moment on.
        $graduated = $this->graduatedMemberIds();

        $rows = [];
        $achievedCount = 0;
        $paidSqft = Money::zero();
        $paidAmount = Money::zero();

        foreach ($this->measurements($period) as $measurement) {
            if (isset($graduated[$measurement->leader_id])) {
                continue;
            }

            $achievedSqft = Money::of($measurement->total_team_sqft);
            $achieved = Money::compare($achievedSqft, $targetSqft) >= 0;

            $rows[] = [
                'member_id' => $measurement->leader_id,
                'period' => $period,
                'target_level' => self::LEVEL,
                'target_sqft' => $targetSqft,
                'rate' => $rate,
                'achieved_sqft' => $achievedSqft,
                'own_sqft' => Money::of($measurement->own_sqft),
                'achieved' => $achieved,
                // Never negative: a surplus is discarded, not a negative shortfall.
                'shortfall_sqft' => $achieved
                    ? Money::zero()
                    : Money::subtract($targetSqft, $achievedSqft),
                'reward_amount' => $achieved ? $reward : Money::zero(),
                // The once-ever guard. NULL on a miss so misses never collide.
                'achieved_level' => $achieved ? self::LEVEL : null,
                'calculation_run_id' => $run->id,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if ($achieved) {
                $achievedCount++;
                $paidSqft = Money::add($paidSqft, $targetSqft);
                $paidAmount = Money::add($paidAmount, $reward);
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            TargetCalculation::insert($chunk);
        }

        $this->postLedger($run, $period, $rate);

        return [
            // Everyone measured is recorded, achievers and non-achievers alike —
            // a miss is a real result the member retries from next month.
            'records' => count($rows),
            // The Sq.Ft. actually PAID ON, which is the threshold once per
            // achiever, not the Sq.Ft. they sold. total_sqft x rate therefore
            // reconciles exactly to total_amount.
            'sqft' => $paidSqft,
            'amount' => $paidAmount,
        ];
    }

    /**
     * Write one ledger row per achiever.
     *
     * The rows are re-read from the table just written so each ledger entry can
     * carry its target_calculation id as `source_id` — that is what makes every
     * ₹150,000 traceable back to the exact verdict that produced it.
     */
    private function postLedger(CalculationRun $run, string $period, string $rate): void
    {
        $achievements = TargetCalculation::query()
            ->where('calculation_run_id', $run->id)
            ->achieved()
            ->get(['id', 'member_id', 'target_sqft', 'reward_amount']);

        $ledger = $achievements->map(fn (TargetCalculation $row) => [
            'member_id' => $row->member_id,
            'reward_type' => RewardType::Target->value,
            'source_type' => 'target_calculation',
            'source_id' => $row->id,
            'period' => $period,
            // The THRESHOLD, not what they sold. sqft x rate = amount holds on
            // every row, which is what reconciliation in Phase 13 relies on.
            'sqft' => $row->target_sqft,
            'rate' => $rate,
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
     * The team figures this period's targets are judged against.
     *
     * @return Collection<int, TeamCalculation>
     */
    private function measurements(string $period): Collection
    {
        return TeamCalculation::query()
            ->forPeriod($period)
            ->orderByDesc('total_team_sqft')
            ->get(['leader_id', 'own_sqft', 'total_team_sqft']);
    }

    /**
     * Members who have already achieved this target in any period, keyed by id.
     *
     * Keyed rather than a list so the engine's lookup stays O(1) per member.
     *
     * @return array<int, true>
     */
    private function graduatedMemberIds(): array
    {
        return TargetCalculation::query()
            ->where('achieved_level', self::LEVEL)
            ->pluck('member_id')
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    /**
     * Team Sales is the input to this engine, so it has to exist first.
     *
     * Recomputing the rollup here instead would let the Target report and the
     * Team Sales report disagree about the same month, which is exactly the kind
     * of divergence a reward system cannot afford.
     */
    private function assertTeamSalesCalculated(string $period): void
    {
        if ($this->runs->completedRun($period, CalculationRunType::TeamSales) === null) {
            throw new RuntimeException(sprintf(
                'Team Sales has not been calculated for %s. Targets are judged on the '
                .'team figures that run produces, so it must be run first.',
                $period,
            ));
        }
    }

    // -----------------------------------------------------------------
    // Read-side helpers (no writes)
    // -----------------------------------------------------------------

    /**
     * What a period would produce, without writing anything.
     *
     * @return array{
     *     measured: int, achieved: int, missed: int, graduated: int,
     *     reward_each: string, total_amount: string,
     *     target_sqft: string, team_sales_ready: bool
     * }
     */
    public function preview(string $period): array
    {
        $this->runs->assertValidPeriod($period);

        $targetSqft = $this->targetSqft();
        $reward = Money::multiply($targetSqft, $this->rate());
        $graduated = $this->graduatedMemberIds();

        $measured = 0;
        $achieved = 0;

        foreach ($this->measurements($period) as $measurement) {
            if (isset($graduated[$measurement->leader_id])) {
                continue;
            }

            $measured++;

            if (Money::compare(Money::of($measurement->total_team_sqft), $targetSqft) >= 0) {
                $achieved++;
            }
        }

        return [
            'measured' => $measured,
            'achieved' => $achieved,
            'missed' => $measured - $achieved,
            'graduated' => count($graduated),
            'reward_each' => $reward,
            'total_amount' => Money::multiply($reward, (string) $achieved),
            'target_sqft' => $targetSqft,
            'team_sales_ready' => $this->runs->completedRun($period, CalculationRunType::TeamSales) !== null,
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
     * The member's team, as a tree, with each node's Sq.Ft. for the period.
     *
     * This is the explanation surface behind a target verdict: the root's
     * subtree total IS the figure that was measured, so the tree can be read as
     * the working behind the number.
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
            <<<SQL
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

    // -----------------------------------------------------------------
    // Frozen inputs
    // -----------------------------------------------------------------

    /**
     * Target 1's threshold. Confirmed at 5,000 and fixed in config.
     *
     * Targets 2 and 3 become admin-configurable in Phases 9-10; this method is
     * where that lookup will move, and every caller already routes through it.
     */
    public function targetSqft(): string
    {
        return Money::of(config('rewards.targets.'.self::LEVEL.'.sqft'));
    }

    public function rate(): string
    {
        return Money::of(RewardType::Target->rate());
    }

    /** The full reward for achieving Target 1: threshold × rate. */
    public function rewardAmount(): string
    {
        return Money::multiply($this->targetSqft(), $this->rate());
    }
}

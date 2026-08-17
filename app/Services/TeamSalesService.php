<?php

namespace App\Services;

use App\Enums\CalculationRunType;
use App\Models\CalculationRun;
use App\Models\Member;
use App\Models\TeamCalculation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Team Sales engine.
 *
 *      team_sqft = own approved sales + all approved sales of every connected
 *                  downline member, at any depth
 *
 * Client-confirmed rules (docs/02_BUSINESS_RULES.md §3-4,
 * docs/05_CALCULATION_ENGINE_SPEC.md §C):
 *
 *  - Every member's calculation is INDEPENDENT. One sale therefore counts in the
 *    seller's own total and again in the team total of every ancestor above
 *    them. That overlap is intended, not double-counting — these are separate
 *    measurements of separate people's teams.
 *  - Depth is unlimited. "All connected downline" means the entire branch, not
 *    the 5 levels the upline reward is capped at. The two rules are unrelated.
 *  - This engine PAYS NOBODY. It writes no reward_ledger rows. It measures the
 *    figure the Target engine will later test against 5,000 / 10,000 / 35,000.
 *
 * The whole network is rolled up in a single recursive query. Walking each
 * leader's branch separately would be O(members × depth) round trips and would
 * re-read the same sales once per ancestor.
 */
class TeamSalesService
{
    private const MAX_DEPTH = 100;

    public function __construct(
        private readonly CalculationRunService $runs,
    ) {}

    public function calculate(string $period, User $initiatedBy): CalculationRun
    {
        return $this->runs->execute(
            $period,
            CalculationRunType::TeamSales,
            $initiatedBy,
            fn (CalculationRun $run) => $this->post($period, $run),
        );
    }

    /**
     * Re-roll the whole network for the period from the sales as they stand now.
     *
     * This engine pays nobody, so nothing here is money — but the Target engine
     * is judged on these figures, which is why a period holding a paid target
     * reward refuses to recalculate at all.
     */
    public function recalculate(string $period, User $initiatedBy): CalculationRun
    {
        return $this->runs->execute(
            $period,
            CalculationRunType::TeamSales,
            $initiatedBy,
            fn (CalculationRun $run) => $this->post($period, $run),
            fn (string $p) => TeamCalculation::where('period', $p)->delete(),
        );
    }

    /**
     * @return array{records: int, sqft: string, amount: string}
     */
    private function post(string $period, CalculationRun $run): array
    {
        $rows = [];
        $records = 0;

        // The company total: each sale counted ONCE, not once per ancestor.
        // Summing total_team_sqft across leaders would multiply every sale by
        // the height of its chain, which is meaningless as a company figure.
        $companySqft = Money::zero();

        foreach ($this->rollup($period) as $row) {
            $rows[] = [
                'leader_id' => $row->leader_id,
                'period' => $period,
                'own_sqft' => Money::of($row->own_sqft),
                'direct_team_sqft' => Money::of($row->direct_team_sqft),
                'total_team_sqft' => Money::of($row->total_team_sqft),
                'contributing_members' => (int) $row->contributing_members,
                // Target columns intentionally untouched — Phases 8-10.
                'target_sqft' => null,
                'achieved' => null,
                'reward_amount' => null,
                'calculation_run_id' => $run->id,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $records++;
            $companySqft = Money::add($companySqft, Money::of($row->own_sqft));
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            TeamCalculation::insert($chunk);
        }

        return [
            'records' => $records,
            'sqft' => $companySqft,
            // No money is produced by this engine.
            'amount' => Money::zero(),
        ];
    }

    /**
     * Roll the whole network up in one pass.
     *
     * The recursive term walks upward from every member, emitting one
     * (seller, leader, depth) row per ancestor plus depth 0 for the member
     * themselves. Joining sales onto that and grouping by leader yields every
     * leader's totals simultaneously.
     *
     * @return Collection<int, object>
     */
    private function rollup(string $period): Collection
    {
        [$year, $month] = array_map('intval', explode('-', $period));

        return collect(DB::select(
            <<<SQL
            WITH RECURSIVE chain AS (
                SELECT id AS seller_id, id AS leader_id, 0 AS depth
                FROM members
                WHERE deleted_at IS NULL

                UNION ALL

                SELECT c.seller_id, m.sponsor_id, c.depth + 1
                FROM chain c
                INNER JOIN members m ON m.id = c.leader_id
                WHERE m.sponsor_id IS NOT NULL
                  AND m.deleted_at IS NULL
                  AND c.depth < ?
            )
            SELECT
                c.leader_id,
                COALESCE(SUM(s.sqft), 0) AS total_team_sqft,
                COALESCE(SUM(CASE WHEN c.depth = 0 THEN s.sqft ELSE 0 END), 0) AS own_sqft,
                COALESCE(SUM(CASE WHEN c.depth = 1 THEN s.sqft ELSE 0 END), 0) AS direct_team_sqft,
                COUNT(DISTINCT CASE WHEN c.depth > 0 THEN s.member_id END) AS contributing_members
            FROM chain c
            INNER JOIN registry_sales s
                ON s.member_id = c.seller_id
               AND s.status = 'approved'
               AND YEAR(s.registry_date) = ?
               AND MONTH(s.registry_date) = ?
            GROUP BY c.leader_id
            HAVING SUM(s.sqft) > 0
            ORDER BY total_team_sqft DESC
            SQL,
            [self::MAX_DEPTH, $year, $month]
        ));
    }

    // -----------------------------------------------------------------
    // Read-side helpers (no writes)
    // -----------------------------------------------------------------

    /**
     * What a period would produce, without writing anything.
     *
     * @return array{leaders: int, company_sqft: string, largest_team: string}
     */
    public function preview(string $period): array
    {
        $this->runs->assertValidPeriod($period);

        $rows = $this->rollup($period);

        return [
            'leaders' => $rows->count(),
            'company_sqft' => Money::sum($rows->pluck('own_sqft')),
            'largest_team' => $rows->isEmpty()
                ? Money::zero()
                : Money::of($rows->first()->total_team_sqft),
        ];
    }

    /**
     * Team totals for one member across periods.
     *
     * @return Collection<int, TeamCalculation>
     */
    public function forMember(Member $member): Collection
    {
        return TeamCalculation::query()
            ->where('leader_id', $member->id)
            ->orderByDesc('period')
            ->get();
    }

    /**
     * Who contributed to a leader's team total in a period, with their depth.
     *
     * This is the explanation surface: it names every member whose sales rolled
     * up into the leader's figure.
     *
     * @return Collection<int, object>
     */
    public function contributors(Member $leader, string $period): Collection
    {
        [$year, $month] = array_map('intval', explode('-', $period));

        return collect(DB::select(
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
                b.id AS member_id,
                b.depth,
                mem.member_code,
                mem.name,
                mem.status,
                SUM(s.sqft) AS sqft,
                COUNT(s.id) AS sales
            FROM branch b
            INNER JOIN members mem ON mem.id = b.id
            INNER JOIN registry_sales s
                ON s.member_id = b.id
               AND s.status = 'approved'
               AND YEAR(s.registry_date) = ?
               AND MONTH(s.registry_date) = ?
            GROUP BY b.id, b.depth, mem.member_code, mem.name, mem.status
            ORDER BY b.depth, sqft DESC
            SQL,
            [$leader->id, self::MAX_DEPTH, $year, $month]
        ));
    }
}

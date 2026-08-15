<?php

namespace App\Services;

use App\Enums\MemberStatus;
use App\Models\Member;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sponsor hierarchy navigation.
 *
 * The network is expected to grow large, so nothing here ever loads the whole
 * tree (docs/04_UI_UX_SPECIFICATION.md: "Never render thousands of nodes
 * initially"). Callers fetch one level at a time and branch totals are resolved
 * for a batch of nodes in a single query rather than one query per node.
 *
 * Descendant walks use MariaDB/MySQL recursive CTEs. `MAX_DEPTH` is a safety
 * valve against a corrupt cycle, not a business rule — the 5-level upline limit
 * is a separate concern and lives in config/rewards.php.
 */
class MemberTreeService
{
    private const MAX_DEPTH = 100;

    /**
     * Top-level members (those without a sponsor).
     *
     * @return Collection<int, Member>
     */
    public function roots(): Collection
    {
        return $this->decorate(
            Member::query()
                ->roots()
                ->withCount('directReferrals')
                ->orderBy('sequence_number')
                ->get()
        );
    }

    /**
     * The direct referrals of one member — a single level, never deeper.
     *
     * @return Collection<int, Member>
     */
    public function children(Member $parent): Collection
    {
        return $this->decorate(
            $parent->directReferrals()
                ->withCount('directReferrals')
                ->orderBy('sequence_number')
                ->get()
        );
    }

    /**
     * Total and active descendant counts for a set of members, in one query.
     *
     * Without this the tree would fire a recursive query per rendered node.
     *
     * @param  array<int, int>  $memberIds
     * @return array<int, array{total: int, active: int}>
     */
    public function branchTotals(array $memberIds): array
    {
        $memberIds = array_values(array_unique(array_filter($memberIds)));

        if ($memberIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));

        // `origin` carries the starting member down through the recursion so a
        // single pass can group totals per requested node.
        $rows = DB::select(
            <<<SQL
            WITH RECURSIVE tree AS (
                SELECT id AS origin, id AS node, status, 0 AS depth
                FROM members
                WHERE id IN ({$placeholders}) AND deleted_at IS NULL

                UNION ALL

                SELECT t.origin, m.id, m.status, t.depth + 1
                FROM members m
                INNER JOIN tree t ON m.sponsor_id = t.node
                WHERE m.deleted_at IS NULL AND t.depth < ?
            )
            SELECT
                origin,
                COUNT(*) - 1 AS total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END)
                    - MAX(CASE WHEN depth = 0 AND status = ? THEN 1 ELSE 0 END) AS active
            FROM tree
            GROUP BY origin
            SQL,
            [...$memberIds, self::MAX_DEPTH, MemberStatus::Active->value, MemberStatus::Active->value]
        );

        $totals = [];

        foreach ($rows as $row) {
            $totals[(int) $row->origin] = [
                'total' => (int) $row->total,
                'active' => (int) $row->active,
            ];
        }

        // Members with no descendants do not appear in the grouped result.
        foreach ($memberIds as $id) {
            $totals[$id] ??= ['total' => 0, 'active' => 0];
        }

        return $totals;
    }

    /**
     * How many levels sit above this member. A root member is level 0.
     */
    public function levelOf(Member $member): int
    {
        return $member->ancestors()->count();
    }

    /**
     * The ancestor ids from the outermost root down to (but excluding) the
     * member. The tree UI expands exactly these nodes to reveal a member.
     *
     * @return array<int, int>
     */
    public function pathToRoot(Member $member): array
    {
        return $member->ancestors()
            ->reverse()
            ->pluck('id')
            ->values()
            ->all();
    }

    /**
     * Every descendant of a member, with its level, as a paginated flat list.
     *
     * Backs the "View Full Downline" control. Paginated because a branch may
     * hold thousands of members and must never arrive in one response.
     *
     * @return LengthAwarePaginator<int, Member>
     */
    public function downline(Member $member, int $perPage = 25, int $page = 1, ?int $maxLevel = null): LengthAwarePaginator
    {
        $bindings = [$member->id, self::MAX_DEPTH];
        $levelFilter = '';

        if ($maxLevel !== null) {
            $levelFilter = 'WHERE level <= ?';
            $bindings[] = $maxLevel;
        }

        $rows = DB::select(
            <<<SQL
            WITH RECURSIVE tree AS (
                SELECT id, sponsor_id, 1 AS level
                FROM members
                WHERE sponsor_id = ? AND deleted_at IS NULL

                UNION ALL

                SELECT m.id, m.sponsor_id, t.level + 1
                FROM members m
                INNER JOIN tree t ON m.sponsor_id = t.id
                WHERE m.deleted_at IS NULL AND t.level < ?
            )
            SELECT id, level FROM tree {$levelFilter}
            SQL,
            $bindings
        );

        $levels = [];
        foreach ($rows as $row) {
            $levels[(int) $row->id] = (int) $row->level;
        }

        $total = count($levels);
        $ids = array_slice(array_keys($levels), ($page - 1) * $perPage, $perPage);

        $members = $ids === []
            ? collect()
            : Member::query()
                ->with('sponsor:id,name,member_code,sponsor_id')
                ->withCount('directReferrals')
                ->whereIn('id', $ids)
                ->get()
                ->sortBy(fn (Member $m) => [$levels[$m->id], $m->sequence_number])
                ->values()
                ->each(fn (Member $m) => $m->setAttribute('level', $levels[$m->id]));

        return new LengthAwarePaginator($members, $total, $perPage, $page);
    }

    /**
     * Search for a member and report where they sit, so the UI can jump to them.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function search(string $term, int $limit = 15): Collection
    {
        return Member::query()
            ->search($term)
            ->with('sponsor:id,name,member_code,sponsor_id')
            ->orderBy('sequence_number')
            ->limit($limit)
            ->get()
            ->map(fn (Member $member) => [
                'id' => $member->id,
                'member_code' => $member->member_code,
                'name' => $member->name,
                'mobile' => $member->mobile,
                'status' => $member->status->value,
                'status_label' => $member->status->label(),
                'level' => $this->levelOf($member),
                'sponsor' => $member->sponsor ? [
                    'id' => $member->sponsor->id,
                    'member_code' => $member->sponsor->member_code,
                    'name' => $member->sponsor->name,
                ] : null,
            ]);
    }

    /**
     * Shape a member for the tree UI.
     *
     * @return array<string, mixed>
     */
    public function toNode(Member $member, int $level): array
    {
        return [
            'id' => $member->id,
            'member_code' => $member->member_code,
            'name' => $member->name,
            'mobile' => $member->mobile,
            'status' => $member->status->value,
            'status_label' => $member->status->label(),
            'level' => $level,
            'direct_count' => (int) ($member->direct_referrals_count ?? 0),
            'team_total' => (int) ($member->branch_total ?? 0),
            'team_active' => (int) ($member->branch_active ?? 0),
            'is_team_leader' => (int) ($member->direct_referrals_count ?? 0) > 0,
            'has_children' => (int) ($member->direct_referrals_count ?? 0) > 0,
            'url' => route('admin.members.show', $member),
        ];
    }

    /**
     * Attach branch totals to a collection using a single batched query.
     *
     * @param  Collection<int, Member>  $members
     * @return Collection<int, Member>
     */
    private function decorate(Collection $members): Collection
    {
        $totals = $this->branchTotals($members->pluck('id')->all());

        return $members->each(function (Member $member) use ($totals) {
            $member->setAttribute('branch_total', $totals[$member->id]['total'] ?? 0);
            $member->setAttribute('branch_active', $totals[$member->id]['active'] ?? 0);
        });
    }
}

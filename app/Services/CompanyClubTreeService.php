<?php

namespace App\Services;

use App\Enums\MemberStatus;
use App\Models\CompanyClubSetting;
use App\Models\Member;
use Illuminate\Support\Collection;

/**
 * The Company Club network, and the upward walk that decides who qualifies.
 *
 * COMPANY CLUB IS A SYSTEM ENTITY, NOT A MEMBER. There is no root member row and
 * none will ever be created. A member with no sponsor sits directly under the
 * Club; the Club is the virtual parent of every such member. Consequences that
 * fall straight out of that, and that this service exists to enforce:
 *
 *   - The Club is NEVER counted as a level. The immediate ACTIVE sponsor of a
 *     seller is level 1, and a seller with no sponsor simply produces no
 *     recipients at all.
 *   - The Club is never a payout member. Nothing here can ever return it.
 *
 * THE WALK. Starting at the seller's sponsor and moving upward:
 *
 *   - an ACTIVE member takes the next level number;
 *   - an INACTIVE member is SKIPPED - the walk continues past them and they do
 *     NOT consume a level;
 *   - the walk stops after `max_upline_levels` ACTIVE members are collected, or
 *     when the chain reaches a member with no sponsor.
 *
 * So the cap is 5 ACTIVE levels, not 5 database hops. A chain of eight members
 * with three inactive still yields five recipients.
 *
 * WHY THIS DUPLICATES UplineRewardService::eligibleUplines() INSTEAD OF CALLING
 * IT. The two walks implement the same rule today and the duplication is
 * deliberate, not an oversight. Company Club is a separate module by explicit
 * instruction, and more importantly the coupling would be financial: a future
 * change to the 50/upline rule would silently move Company Club money for
 * reasons nobody reviewing that change would think to check. The safeguard
 * against silent drift is a test - `the_company_club_walk_agrees_with_the_upline
 * _walk_today` - which fails the moment the two rules diverge, forcing a
 * decision rather than allowing a surprise.
 */
class CompanyClubTreeService
{
    /** Defensive only: a corrupt cycle must never spin a loop. */
    private const DEPTH_GUARD = 100;

    /**
     * The whole network as id => [sponsor_id, active].
     *
     * Loaded once per calculation so the upward walk happens in memory. Walking
     * with queries would be O(sellers x depth) round trips.
     *
     * @return array<int, array{sponsor_id: int|null, active: bool}>
     */
    public function sponsorMap(): array
    {
        $map = [];

        Member::query()
            ->select(['id', 'sponsor_id', 'status'])
            ->orderBy('id')
            ->chunk(2000, function (Collection $members) use (&$map) {
                foreach ($members as $member) {
                    $map[$member->id] = [
                        'sponsor_id' => $member->sponsor_id,
                        'active' => $member->status === MemberStatus::Active,
                    ];
                }
            });

        return $map;
    }

    /**
     * Every member is inside the Company Club network.
     *
     * Deliberately a real, tested predicate rather than an inlined `true`. Today
     * every tree roots at a sponsorless member and the Club is the virtual parent
     * of all of them, so this always holds. If the business ever introduces a
     * member who is outside the Club, this is the one place that changes.
     *
     * @param  array<int, array{sponsor_id: int|null, active: bool}>  $network
     */
    public function isInNetwork(int $memberId, array $network): bool
    {
        return isset($network[$memberId]);
    }

    /**
     * The ACTIVE sponsors above a seller, nearest first.
     *
     * Returns up to `max_upline_levels` entries. Returns an EMPTY array for a
     * member sitting directly under the Company Club, which is exactly right:
     * their sales count toward the pool, but there is nobody above them to pay,
     * and the Club itself is not a payout member.
     *
     * @param  array<int, array{sponsor_id: int|null, active: bool}>  $network
     * @return array<int, array{id: int, level: int, depth: int}>
     */
    public function eligibleUplines(int $sellerId, array $network, ?int $maxLevels = null): array
    {
        $max = $maxLevels ?? CompanyClubSetting::current()->maxLevels();

        $eligible = [];
        $visited = [$sellerId => true];
        $guard = self::DEPTH_GUARD;

        $currentId = $network[$sellerId]['sponsor_id'] ?? null;
        $depth = 0;

        while ($currentId !== null && count($eligible) < $max && $guard-- > 0) {
            // A cycle is prevented at write time; a corrupt row must still not
            // hang the calculation.
            if (isset($visited[$currentId]) || ! isset($network[$currentId])) {
                break;
            }

            $visited[$currentId] = true;
            $depth++;

            if ($network[$currentId]['active']) {
                $eligible[] = [
                    'id' => $currentId,
                    'level' => count($eligible) + 1,
                    'depth' => $depth,
                ];
            }

            $currentId = $network[$currentId]['sponsor_id'];
        }

        return $eligible;
    }

    /**
     * The walk from a seller upward, annotated with what happened to each member.
     *
     * This is the audit surface: it names the members who were skipped for being
     * inactive and the ones that fell beyond the level cap, not only the winners.
     * Stored as `path_snapshot` so a historical explanation keeps saying what was
     * true when the money was calculated.
     *
     * @param  array<int, array{sponsor_id: int|null, active: bool}>  $network
     * @return array<int, array{id: int, depth: int, active: bool, level: int|null, outcome: string}>
     */
    public function annotatedWalk(int $sellerId, array $network, ?int $maxLevels = null): array
    {
        $max = $maxLevels ?? CompanyClubSetting::current()->maxLevels();

        $walk = [];
        $visited = [$sellerId => true];
        $guard = self::DEPTH_GUARD;

        $currentId = $network[$sellerId]['sponsor_id'] ?? null;
        $depth = 0;
        $level = 0;

        while ($currentId !== null && $guard-- > 0) {
            if (isset($visited[$currentId]) || ! isset($network[$currentId])) {
                break;
            }

            $visited[$currentId] = true;
            $depth++;
            $active = $network[$currentId]['active'];

            $assignedLevel = null;

            if (! $active) {
                $outcome = 'skipped-inactive';
            } elseif ($level >= $max) {
                $outcome = 'beyond-limit';
            } else {
                $assignedLevel = ++$level;
                $outcome = 'eligible';
            }

            $walk[] = [
                'id' => $currentId,
                'depth' => $depth,
                'active' => $active,
                'level' => $assignedLevel,
                'outcome' => $outcome,
            ];

            $currentId = $network[$currentId]['sponsor_id'];
        }

        return $walk;
    }

    /**
     * The same annotated walk, resolved to Member models for display.
     *
     * @return array<int, array{member: Member, depth: int, active: bool, level: int|null, outcome: string, reason: string}>
     */
    public function explainChainFor(Member $seller): array
    {
        $network = $this->sponsorMap();
        $walk = $this->annotatedWalk($seller->id, $network);

        if ($walk === []) {
            return [];
        }

        $members = Member::query()
            ->whereIn('id', array_column($walk, 'id'))
            ->get(['id', 'member_code', 'name', 'status', 'sponsor_id'])
            ->keyBy('id');

        $max = CompanyClubSetting::current()->maxLevels();
        $resolved = [];

        foreach ($walk as $step) {
            $member = $members->get($step['id']);

            if ($member === null) {
                continue;
            }

            $resolved[] = [
                'member' => $member,
                'depth' => $step['depth'],
                'active' => $step['active'],
                'level' => $step['level'],
                'outcome' => $step['outcome'],
                'reason' => match ($step['outcome']) {
                    'skipped-inactive' => 'Inactive - skipped, and does not use up a level',
                    'beyond-limit' => 'Beyond the '.$max.' active-level limit',
                    default => 'Eligible at level '.$step['level'],
                },
            ];
        }

        return $resolved;
    }

    // -----------------------------------------------------------------
    // Tree rendering (AJAX, one level at a time)
    // -----------------------------------------------------------------

    /**
     * The members sitting directly under the Company Club.
     *
     * These are the sponsorless members. The Club is their virtual parent; no
     * row represents it.
     *
     * @return Collection<int, Member>
     */
    public function clubMembers(): Collection
    {
        return Member::query()
            ->roots()
            ->orderBy('member_code')
            ->get(['id', 'member_code', 'name', 'status', 'sponsor_id']);
    }

    /**
     * One level of children, for lazy expansion.
     *
     * Never returns a whole branch: the network can be large and the tree screen
     * must not load it in one request.
     *
     * @return Collection<int, Member>
     */
    public function childrenOf(Member $member): Collection
    {
        return Member::query()
            ->where('sponsor_id', $member->id)
            ->orderBy('member_code')
            ->get(['id', 'member_code', 'name', 'status', 'sponsor_id']);
    }

    /**
     * Headline counts for the overview screen.
     *
     * @return array{total: int, active: int, inactive: int, direct_club_members: int, active_direct_club_members: int}
     */
    public function networkSummary(): array
    {
        $total = Member::query()->count();
        $active = Member::query()->active()->count();

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $total - $active,
            'direct_club_members' => Member::query()->roots()->count(),
            'active_direct_club_members' => $this->directClubMemberCount(),
        ];
    }

    /**
     * How many ACTIVE members are attached directly to the Company Club.
     *
     * The recipients of the direct-club pool. Inactive roots are excluded for
     * the same reason inactive sponsors never receive from the main pool: an
     * inactive member is not paid. They are still counted as members of the
     * network, which is why `direct_club_members` above stays a plain count.
     */
    public function directClubMemberCount(): int
    {
        return Member::query()->roots()->active()->count();
    }

    /**
     * The ACTIVE members attached directly to the Company Club.
     *
     * @return Collection<int, Member>
     */
    public function directClubMembers(): Collection
    {
        return Member::query()
            ->roots()
            ->active()
            ->orderBy('member_code')
            ->get(['id', 'member_code', 'name', 'status', 'sponsor_id']);
    }
}

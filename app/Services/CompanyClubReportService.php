<?php

namespace App\Services;

use App\Enums\MemberStatus;
use App\Models\CompanyClubCalculationRun;
use App\Models\CompanyClubEligibilityPath;
use App\Models\CompanyClubReward;
use App\Models\Member;
use App\Models\RegistrySale;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Everything the Company Club screens read. Writes nothing.
 *
 * The job this service really does is answer one question in several shapes:
 * WHY DID THIS MEMBER RECEIVE THIS AMOUNT? A recipient sees the pool, the
 * divisor, their share, and every selling member whose branch reached them -
 * each with the sponsor path walked, the level assigned, and any inactive
 * sponsor that was skipped on the way.
 */
class CompanyClubReportService
{
    public function __construct(
        private readonly CompanyClubService $club,
        private readonly CompanyClubTreeService $tree,
        private readonly CompanyClubCalculationService $calculator,
    ) {}

    /**
     * The overview screen.
     *
     * @return array<string, mixed>
     */
    public function overview(string $period): array
    {
        $settings = $this->club->settings();
        $run = $this->club->latestRun($period);
        $network = $this->tree->networkSummary();
        $live = $this->calculator->compute($period);

        return [
            'period' => $period,
            'settings' => $settings,
            'club_name' => $settings->name(),
            'network' => $network,
            'run' => $run,
            'history' => $this->club->history($period, 5),
            'needs_recalculation' => $this->club->needsRecalculation($period),
            'live' => $live,
            // The second pool, over the same Sq.Ft. base, paid to the members
            // attached directly to the Club. Reported beside the main pool, not
            // added to it - see CompanyClubCalculationService::directClubPool().
            'direct_pool' => $this->calculator->directClubPool($live['total_sqft']),
        ];
    }

    /**
     * The recipients of a run, paginated.
     *
     * @return LengthAwarePaginator<int, CompanyClubReward>
     */
    public function recipients(CompanyClubCalculationRun $run, int $perPage = 25): LengthAwarePaginator
    {
        return CompanyClubReward::query()
            ->where('company_club_run_id', $run->id)
            ->with('member:id,member_code,name,status,sponsor_id')
            ->orderBy('best_level')
            ->orderByDesc('eligibility_path_count')
            ->orderBy('member_id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Recipients without paging, for the distribution tree diagram.
     *
     * @return Collection<int, CompanyClubReward>
     */
    public function allRecipients(CompanyClubCalculationRun $run): Collection
    {
        return CompanyClubReward::query()
            ->where('company_club_run_id', $run->id)
            ->with('member:id,member_code,name,status,sponsor_id')
            ->orderBy('best_level')
            ->orderBy('member_id')
            ->get();
    }

    /**
     * Why one member was paid, in full.
     *
     * Returns null when the member received nothing from this run, which the
     * screen reports plainly rather than showing an empty explanation.
     *
     * @return array<string, mixed>|null
     */
    public function explain(CompanyClubCalculationRun $run, Member $member): ?array
    {
        $reward = CompanyClubReward::query()
            ->where('company_club_run_id', $run->id)
            ->where('member_id', $member->id)
            ->first();

        if ($reward === null) {
            return null;
        }

        $paths = CompanyClubEligibilityPath::query()
            ->where('company_club_run_id', $run->id)
            ->where('eligible_member_id', $member->id)
            ->with('saleMember:id,member_code,name,status')
            ->orderBy('upline_level')
            ->orderBy('sale_member_id')
            ->get();

        // Every member named inside any stored snapshot, resolved in one query
        // rather than one per path.
        $snapshotIds = $paths
            ->flatMap(fn (CompanyClubEligibilityPath $path) => collect($path->path_snapshot ?? [])
                ->pluck('id'))
            ->unique()
            ->values()
            ->all();

        $snapshotMembers = $snapshotIds === []
            ? collect()
            : Member::query()
                ->whereIn('id', $snapshotIds)
                ->get(['id', 'member_code', 'name', 'status'])
                ->keyBy('id');

        return [
            'member' => $member,
            'run' => $run,
            'reward' => $reward,
            'paths' => $paths,
            'snapshot_members' => $snapshotMembers,
            'formula' => sprintf(
                '%s / %d = %s',
                Money::format((string) $run->pool_amount),
                $run->eligible_count,
                Money::format((string) $reward->amount),
            ),
        ];
    }

    /**
     * Which selling members caused a run's pool, largest first.
     *
     * @return Collection<int, object>
     */
    public function sellers(CompanyClubCalculationRun $run): Collection
    {
        return CompanyClubEligibilityPath::query()
            ->where('company_club_run_id', $run->id)
            ->with('saleMember:id,member_code,name,status')
            ->selectRaw('sale_member_id, MAX(sale_member_sqft) as sqft, COUNT(*) as recipients')
            ->groupBy('sale_member_id')
            ->orderByDesc('sqft')
            ->get();
    }

    /**
     * The data behind the calculation tree diagram.
     *
     * Club -> total Sq.Ft. -> rate -> pool -> recipient count -> equal share ->
     * the recipients themselves. One shape, so the view stays presentational.
     *
     * @return array<string, mixed>
     */
    public function calculationTree(CompanyClubCalculationRun $run): array
    {
        $recipients = $this->allRecipients($run);

        return [
            'club_name' => $this->club->settings()->name(),
            'period' => $run->period,
            'total_sqft' => (string) $run->total_sqft,
            'rate' => (string) $run->rate,
            'pool' => (string) $run->pool_amount,
            'count' => (int) $run->eligible_count,
            'share' => (string) $run->equal_share,
            'recipients' => $recipients,
            'residual' => (string) $run->residual_amount,
            'reconciles' => $run->reconciles(),
        ];
    }

    /**
     * A member's Company Club rewards across every period.
     *
     * @return Collection<int, CompanyClubReward>
     */
    public function forMember(Member $member): Collection
    {
        return CompanyClubReward::query()
            ->where('member_id', $member->id)
            ->whereHas('run', fn ($q) => $q->completed())
            ->with('run')
            ->get()
            ->sortByDesc(fn (CompanyClubReward $reward) => $reward->run->period)
            ->values();
    }

    /**
     * Every period that has ever been calculated, newest first.
     *
     * @return Collection<int, string>
     */
    public function calculatedPeriods(): Collection
    {
        return CompanyClubCalculationRun::query()
            ->select('period')
            ->distinct()
            ->orderByDesc('period')
            ->pluck('period');
    }

    /**
     * The whole run history, across all periods, for the history screen.
     *
     * @return LengthAwarePaginator<int, CompanyClubCalculationRun>
     */
    public function runHistory(int $perPage = 25): LengthAwarePaginator
    {
        return CompanyClubCalculationRun::query()
            ->with('initiatedBy:id,name')
            ->orderByDesc('period')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    // -----------------------------------------------------------------
    // Income distribution
    // -----------------------------------------------------------------

    /**
     * How many levels of the network are drawn before "Load more" takes over.
     *
     * Three is enough to see the shape of a branch without turning the page
     * into a wall. Deeper levels arrive on request, one branch at a time.
     */
    public const TREE_DEPTH = 3;

    /**
     * The whole network for one month, as a tree, with sales and rewards on
     * every node.
     *
     * Three queries regardless of how big the network is: the members, the
     * month's sales grouped by member, and the run's rewards. The tree is then
     * assembled in memory. Walking the database per node would be one query per
     * member, which is what makes tree screens collapse on real data.
     *
     * @return array{roots: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function incomeTree(string $period, ?int $depth = null): array
    {
        $depth ??= self::TREE_DEPTH;

        [$members, $childrenOf, $sales, $rewards] = $this->incomeData($period);

        $roots = [];

        foreach ($childrenOf[0] ?? [] as $rootId) {
            $roots[] = $this->buildIncomeNode($rootId, $members, $childrenOf, $sales, $rewards, $depth);
        }

        // Branch totals decide the ordering, so the busiest branch reads first.
        usort($roots, fn (array $a, array $b) => Money::compare($b['branch_sqft'], $a['branch_sqft']));

        return [
            'roots' => $roots,
            'totals' => [
                'members' => count($members),
                'sellers' => $sales->count(),
                'sqft' => Money::sum($sales->values()),
                'recipients' => $rewards->count(),
                'reward' => Money::sum($rewards->pluck('amount')),
            ],
        ];
    }

    /**
     * One branch, fetched when the operator asks for it.
     *
     * @return array<string, mixed>|null
     */
    public function incomeBranch(string $period, int $memberId, ?int $depth = null): ?array
    {
        $depth ??= self::TREE_DEPTH;

        [$members, $childrenOf, $sales, $rewards] = $this->incomeData($period);

        if (! isset($members[$memberId])) {
            return null;
        }

        return $this->buildIncomeNode($memberId, $members, $childrenOf, $sales, $rewards, $depth);
    }

    /**
     * Members, the child index, the month's sales and the run's rewards.
     *
     * @return array{0: array<int, object>, 1: array<int, array<int, int>>, 2: Collection<int, string>, 3: Collection<int, object>}
     */
    private function incomeData(string $period): array
    {
        $members = Member::query()
            ->orderBy('member_code')
            ->get(['id', 'member_code', 'name', 'status', 'sponsor_id'])
            ->keyBy('id');

        // sponsor_id null is indexed under 0, so the roots — the members sitting
        // directly under the Club — are just another bucket.
        $childrenOf = [];

        foreach ($members as $member) {
            $childrenOf[$member->sponsor_id ?? 0][] = $member->id;
        }

        $sales = RegistrySale::query()
            ->approved()
            ->forPeriod($period)
            ->selectRaw('member_id, SUM(sqft) as total_sqft')
            ->groupBy('member_id')
            ->pluck('total_sqft', 'member_id')
            ->map(fn ($sqft) => Money::of($sqft));

        $run = $this->club->latestRun($period);

        $rewards = $run === null
            ? collect()
            : CompanyClubReward::query()
                ->where('company_club_run_id', $run->id)
                ->get(['member_id', 'amount'])
                ->keyBy('member_id');

        return [$members, $childrenOf, $sales, $rewards];
    }

    /**
     * One node and, up to the depth limit, everything beneath it.
     *
     * `branch_sqft` is always the TRUE total for the whole branch, even where
     * the children are not drawn — a collapsed branch must not appear to have
     * sold less than it did.
     *
     * @param  array<int, object>  $members
     * @param  array<int, array<int, int>>  $childrenOf
     * @param  Collection<int, string>  $sales
     * @param  Collection<int, object>  $rewards
     * @return array<string, mixed>
     */
    private function buildIncomeNode(
        int $id,
        $members,
        array $childrenOf,
        Collection $sales,
        Collection $rewards,
        int $depth,
    ): array {
        $member = $members[$id];
        $ownSqft = $sales[$id] ?? Money::zero();
        $childIds = $childrenOf[$id] ?? [];

        $children = [];
        $branchSqft = $ownSqft;

        foreach ($childIds as $childId) {
            $child = $this->buildIncomeNode($childId, $members, $childrenOf, $sales, $rewards, $depth - 1);
            $branchSqft = Money::add($branchSqft, $child['branch_sqft']);
            $children[] = $child;
        }

        usort($children, fn (array $a, array $b) => Money::compare($b['branch_sqft'], $a['branch_sqft']));

        return [
            'id' => $id,
            'member_code' => $member->member_code,
            'name' => $member->name,
            'active' => $member->status === MemberStatus::Active,
            'own_sqft' => $ownSqft,
            'branch_sqft' => $branchSqft,
            'reward' => isset($rewards[$id]) ? Money::of($rewards[$id]->amount) : null,
            'child_count' => count($childIds),
            // Beyond the depth limit the children are computed (so the branch
            // total stays honest) but not handed to the view.
            'children' => $depth > 0 ? $children : [],
            'collapsed' => $depth <= 0 && $childIds !== [],
        ];
    }

    /**
     * Each selling member with the active sponsors their sale paid.
     *
     * Deliberately no level numbering: the chain reads bottom-up and the order
     * IS the answer. Inactive members that were skipped are shown greyed, since
     * a chain that silently jumped over somebody would look wrong rather than
     * simple.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sellerChains(string $period): array
    {
        $network = $this->tree->sponsorMap();
        $eligible = $this->calculator->eligibleSellerTotals($period, $network);
        $excluded = $this->calculator->excludedSellerTotals($period, $network);

        $run = $this->club->latestRun($period);

        $rewards = $run === null
            ? collect()
            : CompanyClubReward::query()
                ->where('company_club_run_id', $run->id)
                ->get(['member_id', 'amount'])
                ->keyBy('member_id');

        $everyone = Member::query()
            ->get(['id', 'member_code', 'name', 'status'])
            ->keyBy('id');

        $chains = [];

        foreach ($eligible as $sellerId => $sqft) {
            $sellerId = (int) $sellerId;
            $walk = $this->tree->annotatedWalk($sellerId, $network);

            $chain = [];

            foreach ($walk as $step) {
                // Everything past the limit is noise on a page whose job is to
                // show who got paid.
                if ($step['outcome'] === 'beyond-limit') {
                    continue;
                }

                $chain[] = [
                    'member' => $everyone[$step['id']] ?? null,
                    'skipped' => $step['outcome'] === 'skipped-inactive',
                    'reward' => isset($rewards[$step['id']])
                        ? Money::of($rewards[$step['id']]->amount)
                        : null,
                ];
            }

            $chains[] = [
                'seller' => $everyone[$sellerId] ?? null,
                'sqft' => $sqft,
                'excluded' => false,
                'chain' => $chain,
                'paid_count' => collect($chain)->whereNotNull('reward')->count(),
            ];
        }

        // Inactive sellers appear too, marked, so an operator can see exactly
        // which sales were left out and why.
        foreach ($excluded as $sellerId => $sqft) {
            $chains[] = [
                'seller' => $everyone[(int) $sellerId] ?? null,
                'sqft' => $sqft,
                'excluded' => true,
                'chain' => [],
                'paid_count' => 0,
            ];
        }

        usort($chains, function (array $a, array $b) {
            // Counted sales first, then biggest.
            if ($a['excluded'] !== $b['excluded']) {
                return $a['excluded'] <=> $b['excluded'];
            }

            return Money::compare($b['sqft'], $a['sqft']);
        });

        return $chains;
    }
}

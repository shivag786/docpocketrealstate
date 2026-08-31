<?php

namespace App\Services;

use App\Models\CompanyClubSetting;
use App\Models\Member;
use App\Models\RegistrySale;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * The Company Club arithmetic. WRITES NOTHING.
 *
 * This class has no access to the ledger, no run, no transaction and no user. It
 * takes a period and returns what the month would produce. That is not a
 * stylistic choice: the specification requires that Preview cannot create a
 * financial entry, and the strongest way to guarantee it is for the object that
 * computes to have no ability to write. Preview and the real calculation call
 * exactly this method, so what an admin approves is what gets written.
 *
 * THE RULE, in the order it is applied:
 *
 *   1. Eligible sales = approved, in the period, sold by an ACTIVE member who is
 *      inside the Company Club network. An INACTIVE seller's sales are excluded
 *      entirely - they add nothing to the pool and generate no recipients.
 *   2. total_sqft = SUM(eligible sqft).
 *   3. pool = total_sqft x rate. ONE pool for the whole month. There is never a
 *      separate pool per seller.
 *   4. For each eligible seller, walk upward collecting ACTIVE sponsors: nearest
 *      is level 1, inactive members are skipped without consuming a level, stop
 *      at 5 active levels or at the top of the chain. The Company Club is never a
 *      level and never a recipient.
 *   5. Combine recipients from every selling branch, then DE-DUPLICATE. A member
 *      qualifying through four branches is one recipient with four reasons.
 *   6. share = pool / unique recipient count, rounded half-up to 2 decimals.
 *
 * ONE INVARIANT HOLDS ON EVERY RESULT:
 *
 *      distributed = pool + residual
 *
 * `residual` absorbs two different real situations and is never hidden:
 *   - a few paise, because each share is rounded independently and the shares
 *     need not re-sum to the pool (the Phase 6 upline precedent); and
 *   - the whole pool, negatively, when there are eligible SALES but no eligible
 *     RECIPIENTS. That happens when every seller sits directly under the Club:
 *     their sales legitimately swell the pool but there is nobody above them to
 *     pay. The money is not lost, it is undistributed, and the screens say so.
 */
class CompanyClubCalculationService
{
    public function __construct(
        private readonly CompanyClubTreeService $tree,
    ) {}

    /**
     * Work out the whole month. Writes nothing.
     *
     * @return array{
     *     period: string,
     *     rate: string,
     *     max_levels: int,
     *     total_sqft: string,
     *     pool_amount: string,
     *     eligible_count: int,
     *     equal_share: string,
     *     distributed_amount: string,
     *     residual_amount: string,
     *     seller_count: int,
     *     excluded_seller_count: int,
     *     excluded_sqft: string,
     *     recipients: array<int, array{member_id: int, amount: string, best_level: int, path_count: int}>,
     *     paths: array<int, array{sale_member_id: int, eligible_member_id: int, upline_level: int, chain_depth: int, sale_member_sqft: string, path_snapshot: array<int, array<string, mixed>>}>
     * }
     */
    public function compute(string $period): array
    {
        $settings = CompanyClubSetting::current();
        $rate = $settings->rate();
        $maxLevels = $settings->maxLevels();

        $network = $this->tree->sponsorMap();
        $eligibleSellers = $this->eligibleSellerTotals($period, $network);
        $excluded = $this->excludedSellerTotals($period, $network);

        $totalSqft = Money::sum($eligibleSellers->values());
        $pool = Money::multiply($totalSqft, $rate);

        // Collected per recipient so duplicates across branches collapse to one
        // payout while every reason survives.
        $recipients = [];
        $paths = [];

        foreach ($eligibleSellers as $sellerId => $sellerSqft) {
            $sellerId = (int) $sellerId;
            $uplines = $this->tree->eligibleUplines($sellerId, $network, $maxLevels);

            // A member directly under the Company Club: their Sq.Ft. is already
            // in the pool above, and there is deliberately nobody to pay.
            if ($uplines === []) {
                continue;
            }

            $snapshot = $this->tree->annotatedWalk($sellerId, $network, $maxLevels);

            foreach ($uplines as $upline) {
                $recipientId = $upline['id'];

                if (! isset($recipients[$recipientId])) {
                    $recipients[$recipientId] = [
                        'member_id' => $recipientId,
                        'best_level' => $upline['level'],
                        'path_count' => 0,
                    ];
                }

                $recipients[$recipientId]['path_count']++;
                $recipients[$recipientId]['best_level'] = min(
                    $recipients[$recipientId]['best_level'],
                    $upline['level'],
                );

                $paths[] = [
                    'sale_member_id' => $sellerId,
                    'eligible_member_id' => $recipientId,
                    'upline_level' => $upline['level'],
                    'chain_depth' => $upline['depth'],
                    'sale_member_sqft' => $sellerSqft,
                    // Trimmed to the members between the seller and this
                    // recipient, so the stored reason answers exactly the
                    // question "how did this sale reach this member".
                    'path_snapshot' => $this->snapshotUpTo($snapshot, $upline['depth']),
                ];
            }
        }

        $count = count($recipients);

        // Division by zero is impossible rather than guarded-against: with no
        // recipients there is nothing to divide and the pool stays undistributed.
        $share = $count > 0 ? Money::divide($pool, (string) $count) : Money::zero();
        $distributed = $count > 0 ? Money::multiply($share, (string) $count) : Money::zero();

        foreach ($recipients as $id => $recipient) {
            $recipients[$id]['amount'] = $share;
        }

        // Nearest level first, so the list reads as "closest to the sales" first.
        uasort($recipients, fn (array $a, array $b) => [$a['best_level'], -$a['path_count']]
            <=> [$b['best_level'], -$b['path_count']]);

        return [
            'period' => $period,
            'rate' => $rate,
            'max_levels' => $maxLevels,
            'total_sqft' => $totalSqft,
            'pool_amount' => $pool,
            'eligible_count' => $count,
            'equal_share' => $share,
            'distributed_amount' => $distributed,
            'residual_amount' => Money::subtract($distributed, $pool),
            'seller_count' => $eligibleSellers->count(),
            'excluded_seller_count' => $excluded->count(),
            'excluded_sqft' => Money::sum($excluded->values()),
            'recipients' => array_values($recipients),
            'paths' => $paths,
        ];
    }

    /**
     * The DIRECT CLUB pool. Writes nothing, exactly like compute().
     *
     * A second, separate distribution over the same month:
     *
     *   pool  = the same eligible Sq.Ft. that feeds the main pool x 30
     *   who   = the ACTIVE members attached DIRECTLY to the Company Club, i.e.
     *           the members with no sponsor. There is no upline walk and there
     *           are no levels - being at the top of a tree IS the qualification.
     *   share = pool / that count, split equally
     *
     * TWO THINGS THIS IS NOT. It is not the main pool at a different rate: the
     * recipients are a different set, and in the ordinary case a disjoint one,
     * because the main pool pays sponsors and this pays roots. And it is not
     * money that has been paid - nothing here reaches the ledger. It is a
     * reported figure on the overview until the client asks for a run.
     *
     * The Sq.Ft. base is deliberately shared with the main pool, so the
     * inactive-seller exclusion applies here too and the two pools can never
     * disagree about how big the month was.
     *
     * @param  string  $totalSqft  the eligible Sq.Ft. from compute()
     * @return array{
     *     rate: string,
     *     total_sqft: string,
     *     pool_amount: string,
     *     eligible_count: int,
     *     equal_share: string,
     *     distributed_amount: string,
     *     residual_amount: string
     * }
     */
    public function directClubPool(string $totalSqft): array
    {
        $rate = $this->directClubRate();
        $pool = Money::multiply($totalSqft, $rate);
        $count = $this->tree->directClubMemberCount();

        // Same shape as compute(): with nobody eligible there is nothing to
        // divide, and the whole pool shows as an undistributed residual rather
        // than quietly vanishing.
        $share = $count > 0 ? Money::divide($pool, (string) $count) : Money::zero();
        $distributed = $count > 0 ? Money::multiply($share, (string) $count) : Money::zero();

        return [
            'rate' => $rate,
            'total_sqft' => $totalSqft,
            'pool_amount' => $pool,
            'eligible_count' => $count,
            'equal_share' => $share,
            'distributed_amount' => $distributed,
            'residual_amount' => Money::subtract($distributed, $pool),
        ];
    }

    /**
     * The direct-club rate.
     *
     * Config rather than the settings table: the main rate is admin-editable
     * because runs freeze it, and nothing freezes this one yet. When the direct
     * club gets its own runs, this moves to `company_club_settings` beside it.
     */
    public function directClubRate(): string
    {
        return Money::of(config('rewards.company_club.direct_rate', 30));
    }

    /**
     * Each ACTIVE seller's approved Sq.Ft. for the period.
     *
     * The join to `members` is what enforces the inactive-seller rule, and it is
     * the one place Company Club deliberately diverges from every other engine:
     * Direct, Upline, Team Sales and Target all count a sale regardless of the
     * seller's status. A month with an inactive seller will therefore show a
     * smaller Company Club total than Direct total, correctly.
     *
     * @param  array<int, array{sponsor_id: int|null, active: bool}>  $network
     * @return Collection<int, string> keyed by member id
     */
    public function eligibleSellerTotals(string $period, array $network): Collection
    {
        return RegistrySale::query()
            ->approved()
            ->forPeriod($period)
            ->join('members', 'members.id', '=', 'registry_sales.member_id')
            ->whereNull('members.deleted_at')
            ->where('members.status', 'active')
            ->selectRaw('registry_sales.member_id, SUM(registry_sales.sqft) as total_sqft')
            ->groupBy('registry_sales.member_id')
            ->orderBy('registry_sales.member_id')
            ->pluck('total_sqft', 'member_id')
            ->filter(fn ($sqft, $memberId) => $this->tree->isInNetwork((int) $memberId, $network))
            ->map(fn ($sqft) => Money::of($sqft));
    }

    /**
     * Sales excluded from the pool because their seller is INACTIVE.
     *
     * Reported rather than dropped in silence. An operator looking at a Company
     * Club total lower than the month's real sales is entitled to see why, and
     * historical records stay visible exactly as the specification requires.
     *
     * @param  array<int, array{sponsor_id: int|null, active: bool}>  $network
     * @return Collection<int, string> keyed by member id
     */
    public function excludedSellerTotals(string $period, array $network): Collection
    {
        return RegistrySale::query()
            ->approved()
            ->forPeriod($period)
            ->join('members', 'members.id', '=', 'registry_sales.member_id')
            ->whereNull('members.deleted_at')
            ->where('members.status', '!=', 'active')
            ->selectRaw('registry_sales.member_id, SUM(registry_sales.sqft) as total_sqft')
            ->groupBy('registry_sales.member_id')
            ->orderBy('registry_sales.member_id')
            ->pluck('total_sqft', 'member_id')
            ->map(fn ($sqft) => Money::of($sqft));
    }

    /**
     * The part of the walk between the seller and one recipient.
     *
     * @param  array<int, array{id: int, depth: int, active: bool, level: int|null, outcome: string}>  $snapshot
     * @return array<int, array<string, mixed>>
     */
    private function snapshotUpTo(array $snapshot, int $depth): array
    {
        return array_values(array_filter(
            $snapshot,
            fn (array $step) => $step['depth'] <= $depth,
        ));
    }

    /**
     * Resolve member models for a computed result, for display.
     *
     * @param  array<string, mixed>  $result
     * @return Collection<int, Member>
     */
    public function membersFor(array $result): Collection
    {
        $ids = array_merge(
            array_column($result['recipients'], 'member_id'),
            array_column($result['paths'], 'sale_member_id'),
        );

        if ($ids === []) {
            return collect();
        }

        return Member::query()
            ->whereIn('id', array_unique($ids))
            ->get(['id', 'member_code', 'name', 'status', 'sponsor_id'])
            ->keyBy('id');
    }
}

<?php

namespace App\Services;

use App\Enums\CalculationRunType;
use App\Enums\LedgerStatus;
use App\Enums\MemberStatus;
use App\Enums\RewardType;
use App\Models\CalculationRun;
use App\Models\Member;
use App\Models\RegistrySale;
use App\Models\RewardLedger;
use App\Models\UplineCalculation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Upline reward engine.
 *
 *      pool  = seller's OWN approved Sq.Ft. for the month × ₹50
 *      share = pool / actual eligible upline count
 *
 * Client-confirmed rules (docs/02_BUSINESS_RULES.md §2, plus 2026-08-15):
 *
 *  - The pool comes from the seller's MONTHLY total, not from each sale
 *    individually. One distribution per seller per month.
 *  - Walk upward from the seller's sponsor. Only ACTIVE members are eligible;
 *    an inactive member is SKIPPED and the walk continues past them
 *    ("compression"). Maximum 5 eligible uplines.
 *  - The whole pool is divided equally by the ACTUAL eligible count:
 *    5 → /5, 4 → /4, 3 → /3, 2 → /2, 1 → the full pool, 0 → nothing at all.
 *  - Shares are rounded off to 2 decimals.
 *  - Target achievement has NO effect. Nothing here may consult a target.
 *
 * Independent of the Direct engine: the same sales feed both, but neither is
 * derived from the other (docs/02_BUSINESS_RULES.md §8).
 */
class UplineRewardService
{
    public function __construct(
        private readonly CalculationRunService $runs,
    ) {}

    public function calculate(string $period, User $initiatedBy): CalculationRun
    {
        return $this->runs->execute(
            $period,
            CalculationRunType::Upline,
            $initiatedBy,
            fn (CalculationRun $run) => $this->post($period, $run),
        );
    }

    /**
     * @return array{records: int, sqft: string, amount: string}
     */
    private function post(string $period, CalculationRun $run): array
    {
        $rate = RewardType::Upline->rate();
        $network = $this->sponsorMap();

        $records = 0;
        $totalSqft = Money::zero();
        $totalAmount = Money::zero();

        $ledgerRows = [];
        $calcRows = [];

        foreach ($this->sellerTotals($period) as $sellerId => $sellerSqft) {
            $pool = Money::multiply($sellerSqft, $rate);

            $uplines = $this->eligibleUplines((int) $sellerId, $network);

            // 0 eligible uplines → no calculation at all, not a zero-value row.
            if ($uplines === []) {
                continue;
            }

            $count = count($uplines);
            $share = Money::divide($pool, (string) $count);

            foreach ($uplines as $index => $upline) {
                $level = $index + 1;

                $ledgerRows[] = [
                    'member_id' => $upline['id'],
                    'reward_type' => RewardType::Upline->value,
                    // Sourced from the seller's monthly total, not a single sale.
                    'source_type' => 'member_period',
                    'source_id' => $sellerId,
                    'period' => $period,
                    'sqft' => $sellerSqft,
                    'rate' => $rate,
                    'amount' => $share,
                    'status' => LedgerStatus::Posted->value,
                    'calculation_run_id' => $run->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $calcRows[] = [
                    'period' => $period,
                    'seller_id' => $sellerId,
                    'receiver_id' => $upline['id'],
                    'upline_level' => $level,
                    'chain_depth' => $upline['depth'],
                    'seller_sqft' => $sellerSqft,
                    'pool_rate' => $rate,
                    'pool_amount' => $pool,
                    'eligible_upline_count' => $count,
                    'receiver_amount' => $share,
                    'calculation_run_id' => $run->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $records++;
                $totalAmount = Money::add($totalAmount, $share);
            }

            // Counted once per seller: this is the Sq.Ft. that generated the
            // pool, not a per-receiver figure, so it must not be multiplied by
            // the number of receivers.
            $totalSqft = Money::add($totalSqft, $sellerSqft);
        }

        if ($ledgerRows !== []) {
            foreach (array_chunk($ledgerRows, 500) as $chunk) {
                RewardLedger::insert($chunk);
            }

            foreach (array_chunk($calcRows, 500) as $chunk) {
                UplineCalculation::insert($chunk);
            }
        }

        return [
            'records' => $records,
            'sqft' => $totalSqft,
            'amount' => $totalAmount,
        ];
    }

    /**
     * Each seller's own approved Sq.Ft. for the period.
     *
     * @return Collection<int, string> keyed by member id
     */
    private function sellerTotals(string $period): Collection
    {
        return RegistrySale::query()
            ->approved()
            ->forPeriod($period)
            ->selectRaw('member_id, SUM(sqft) as total_sqft')
            ->groupBy('member_id')
            ->orderBy('member_id')
            ->pluck('total_sqft', 'member_id')
            ->map(fn ($sqft) => Money::of($sqft));
    }

    /**
     * The whole network as id => [sponsor_id, status].
     *
     * Loaded once per run so the upward walk is done in memory. Walking the
     * chain per seller with queries would be O(sellers × depth) round trips.
     *
     * @return array<int, array{sponsor_id: int|null, active: bool}>
     */
    private function sponsorMap(): array
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
     * Eligible uplines for a seller, nearest first.
     *
     * Walks upward from the seller's sponsor. Inactive members are skipped and
     * the walk continues past them, so the result is up to 5 ACTIVE uplines
     * however deep they sit. Returns fewer than 5 — possibly none — when the
     * chain runs out.
     *
     * @param  array<int, array{sponsor_id: int|null, active: bool}>  $network
     * @return array<int, array{id: int, depth: int}>
     */
    public function eligibleUplines(int $sellerId, array $network): array
    {
        $max = (int) config('rewards.upline.max_levels', 5);
        $guard = (int) config('members.sponsor.max_depth_guard', 100);

        $eligible = [];
        $visited = [$sellerId => true];

        $currentId = $network[$sellerId]['sponsor_id'] ?? null;
        $depth = 0;

        while ($currentId !== null && count($eligible) < $max && $guard-- > 0) {
            // Defensive: a cycle is prevented at write time, but a corrupt row
            // must never spin this loop.
            if (isset($visited[$currentId]) || ! isset($network[$currentId])) {
                break;
            }

            $visited[$currentId] = true;
            $depth++;

            if ($network[$currentId]['active']) {
                $eligible[] = ['id' => $currentId, 'depth' => $depth];
            }

            $currentId = $network[$currentId]['sponsor_id'];
        }

        return $eligible;
    }

    // -----------------------------------------------------------------
    // Read-side helpers (no writes)
    // -----------------------------------------------------------------

    /**
     * What a period would produce, without writing anything.
     *
     * @return array{sellers: int, distributing: int, sqft: string, pool: string, distributed: string, residual: string, receipts: int}
     */
    public function preview(string $period): array
    {
        $this->runs->assertValidPeriod($period);

        $rate = RewardType::Upline->rate();
        $network = $this->sponsorMap();

        $sellers = 0;
        $distributing = 0;
        $receipts = 0;
        $sqft = Money::zero();
        $pool = Money::zero();
        $distributed = Money::zero();

        foreach ($this->sellerTotals($period) as $sellerId => $sellerSqft) {
            $sellers++;

            $uplines = $this->eligibleUplines((int) $sellerId, $network);

            if ($uplines === []) {
                continue;
            }

            $sellerPool = Money::multiply($sellerSqft, $rate);
            $count = count($uplines);
            $share = Money::divide($sellerPool, (string) $count);

            $distributing++;
            $receipts += $count;
            $sqft = Money::add($sqft, $sellerSqft);
            $pool = Money::add($pool, $sellerPool);
            $distributed = Money::add($distributed, Money::multiply($share, (string) $count));
        }

        return [
            'sellers' => $sellers,
            'distributing' => $distributing,
            'receipts' => $receipts,
            'sqft' => $sqft,
            'pool' => $pool,
            'distributed' => $distributed,
            // Rounding each share independently means the shares need not re-sum
            // to the pool. Surfaced rather than hidden.
            'residual' => Money::subtract($distributed, $pool),
        ];
    }

    /**
     * A member's upline rewards by period.
     *
     * @return Collection<int, object>
     */
    public function forMember(Member $member): Collection
    {
        return RewardLedger::query()
            ->ofType(RewardType::Upline)
            ->where('member_id', $member->id)
            ->selectRaw('period, COUNT(*) as entries, SUM(amount) as amount')
            ->groupBy('period')
            ->orderByDesc('period')
            ->get();
    }

    /**
     * The full working behind a member's upline rewards in one period.
     *
     * @return Collection<int, UplineCalculation>
     */
    public function explainFor(Member $member, string $period): Collection
    {
        return UplineCalculation::query()
            ->forPeriod($period)
            ->where('receiver_id', $member->id)
            ->with('seller:id,member_code,name')
            ->orderBy('upline_level')
            ->get();
    }

    /**
     * The chain above a member, annotated with why each ancestor does or does not
     * qualify.
     *
     * This is the explanation surface for the whole rule: it shows the walk from
     * the seller upward, which members were skipped for being inactive, which
     * five qualified, and which fell beyond the limit.
     *
     * @return array<int, array{member: Member, depth: int, active: bool, eligible: bool, level: int|null, reason: string}>
     */
    public function annotatedChain(Member $seller): array
    {
        $max = (int) config('rewards.upline.max_levels', 5);

        $chain = [];
        $level = 0;
        $depth = 0;

        foreach ($seller->ancestors() as $ancestor) {
            $depth++;
            $active = $ancestor->isActive();
            $eligible = false;
            $assignedLevel = null;

            if (! $active) {
                $reason = 'Inactive — skipped, the walk continues upward';
            } elseif ($level >= $max) {
                $reason = 'Beyond the '.$max.'-upline limit';
            } else {
                $eligible = true;
                $assignedLevel = ++$level;
                $reason = 'Eligible — receives an equal share';
            }

            $chain[] = [
                'member' => $ancestor,
                'depth' => $depth,
                'active' => $active,
                'eligible' => $eligible,
                'level' => $assignedLevel,
                'reason' => $reason,
            ];
        }

        return $chain;
    }

    /**
     * What one member's OWN sales paid out in a period, and to whom.
     *
     * The member is the seller here: their Sq.Ft. created the pool.
     *
     * @return array{sqft: string, pool: string, count: int, share: string, rows: Collection<int, UplineCalculation>}
     */
    public function distributionBySeller(Member $seller, string $period): array
    {
        $rows = UplineCalculation::query()
            ->forPeriod($period)
            ->where('seller_id', $seller->id)
            ->with('receiver:id,member_code,name,status')
            ->orderBy('upline_level')
            ->get();

        $sqft = Money::of(
            RegistrySale::query()
                ->approved()
                ->forPeriod($period)
                ->where('member_id', $seller->id)
                ->sum('sqft')
        );

        return [
            'sqft' => $sqft,
            'pool' => Money::multiply($sqft, RewardType::Upline->rate()),
            'count' => $rows->count(),
            'share' => $rows->first()?->receiver_amount ?? Money::zero(),
            'rows' => $rows,
        ];
    }

    /**
     * What a member RECEIVED in a period, and from which sellers.
     *
     * @return Collection<int, UplineCalculation>
     */
    public function receiptsFor(Member $member, string $period): Collection
    {
        return UplineCalculation::query()
            ->forPeriod($period)
            ->where('receiver_id', $member->id)
            ->with('seller:id,member_code,name')
            ->orderByDesc('receiver_amount')
            ->get();
    }

    /**
     * The path from the outermost root down to a member.
     *
     * @return Collection<int, Member>
     */
    public function pathFromRoot(Member $member): Collection
    {
        return $member->ancestors()->reverse()->values();
    }
}

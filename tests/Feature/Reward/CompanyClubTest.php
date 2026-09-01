<?php

namespace Tests\Feature\Reward;

use App\Enums\CalculationRunStatus;
use App\Enums\LedgerStatus;
use App\Enums\RewardType;
use App\Models\CompanyClubEligibilityPath;
use App\Models\CompanyClubReward;
use App\Models\CompanyClubSetting;
use App\Models\Member;
use App\Models\RegistrySale;
use App\Models\RewardLedger;
use App\Models\User;
use App\Services\CompanyClubCalculationService;
use App\Services\CompanyClubService;
use App\Services\CompanyClubTreeService;
use App\Services\DirectRewardService;
use App\Services\PeriodRecalculationService;
use App\Services\UplineRewardService;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Company Club engine.
 *
 * The acceptance case from the specification, which several tests below build
 * toward:
 *
 *      50,000 Sq.Ft. x 50 = 25,00,000 pool
 *      25,00,000 / 10 recipients = 2,50,000 each
 */
class CompanyClubTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CompanyClubService $club;

    private CompanyClubCalculationService $calculator;

    private CompanyClubTreeService $tree;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->club = app(CompanyClubService::class);
        $this->calculator = app(CompanyClubCalculationService::class);
        $this->tree = app(CompanyClubTreeService::class);
    }

    private function period(): string
    {
        return now()->subMonth()->format('Y-m');
    }

    private function sell(Member $member, string $sqft, ?string $period = null): RegistrySale
    {
        return RegistrySale::factory()
            ->forMember($member)
            ->sqft($sqft)
            ->inPeriod($period ?? $this->period())
            ->create();
    }

    /**
     * A chain beneath the Company Club: club -> first -> second -> ...
     *
     * @param  array<int, bool>  $activeFlags  one per member, top-down
     * @return array<int, Member>
     */
    private function chain(array $activeFlags): array
    {
        $members = [];
        $previous = null;

        foreach ($activeFlags as $active) {
            $factory = Member::factory();
            $factory = $active ? $factory : $factory->inactive();
            $factory = $previous === null ? $factory->root() : $factory->sponsoredBy($previous);

            $previous = $factory->create();
            $members[] = $previous;
        }

        return $members;
    }

    // -----------------------------------------------------------------
    // Membership: the Club is a system entity, never a member row
    // -----------------------------------------------------------------

    #[Test]
    public function a_member_created_without_a_sponsor_belongs_to_the_company_club(): void
    {
        $member = Member::factory()->root()->create();

        $this->assertNull($member->sponsor_id);
        $this->assertTrue($this->tree->isInNetwork($member->id, $this->tree->sponsorMap()));
        $this->assertTrue($this->tree->clubMembers()->contains('id', $member->id));
    }

    #[Test]
    public function no_root_member_is_ever_created_to_represent_the_company_club(): void
    {
        $shiva = Member::factory()->root()->create(['name' => 'Shiva']);
        Member::factory()->sponsoredBy($shiva)->create();

        $this->sell($shiva, '1000.00');
        $this->club->calculate($this->period(), $this->admin);

        // Exactly the members that were created. No synthetic Club row appeared.
        $this->assertSame(2, Member::query()->count());
        $this->assertSame(1, Member::query()->whereNull('sponsor_id')->count());
        $this->assertSame(0, Member::query()->where('name', 'like', '%Company Club%')->count());
        $this->assertSame(0, Member::query()->where('name', 'like', '%ROOT%')->count());
    }

    // -----------------------------------------------------------------
    // Levels
    // -----------------------------------------------------------------

    #[Test]
    public function the_immediate_active_sponsor_is_level_one(): void
    {
        [$shiva, $s1] = $this->chain([true, true]);

        $uplines = $this->tree->eligibleUplines($s1->id, $this->tree->sponsorMap());

        $this->assertCount(1, $uplines);
        $this->assertSame($shiva->id, $uplines[0]['id']);
        $this->assertSame(1, $uplines[0]['level']);
    }

    #[Test]
    public function the_sponsors_sponsor_is_level_two(): void
    {
        [$shiva, $s1, $s2] = $this->chain([true, true, true]);

        $uplines = $this->tree->eligibleUplines($s2->id, $this->tree->sponsorMap());

        $this->assertCount(2, $uplines);
        $this->assertSame([$s1->id, 1], [$uplines[0]['id'], $uplines[0]['level']]);
        $this->assertSame([$shiva->id, 2], [$uplines[1]['id'], $uplines[1]['level']]);
    }

    #[Test]
    public function five_active_sponsors_are_collected_at_levels_one_to_five(): void
    {
        $members = $this->chain([true, true, true, true, true, true]);
        $seller = end($members);

        $uplines = $this->tree->eligibleUplines($seller->id, $this->tree->sponsorMap());

        $this->assertCount(5, $uplines);
        $this->assertSame([1, 2, 3, 4, 5], array_column($uplines, 'level'));
    }

    #[Test]
    public function the_company_club_is_never_counted_as_a_level(): void
    {
        // Club -> shiva -> s1. Walking up from s1 reaches shiva, then the Club
        // boundary. Shiva is level 1 and NOTHING is level 2.
        [$shiva, $s1] = $this->chain([true, true]);

        $uplines = $this->tree->eligibleUplines($s1->id, $this->tree->sponsorMap());

        $this->assertCount(1, $uplines);
        $this->assertSame($shiva->id, $uplines[0]['id']);
        $this->assertSame(1, $uplines[0]['level']);
    }

    #[Test]
    public function an_inactive_sponsor_is_skipped_and_does_not_consume_a_level(): void
    {
        // Shiva ACTIVE -> S1 INACTIVE -> S2 ACTIVE -> S3 ACTIVE
        // For S3: S2 = L1, S1 skipped, Shiva = L2.
        [$shiva, $s1, $s2, $s3] = $this->chain([true, false, true, true]);

        $uplines = $this->tree->eligibleUplines($s3->id, $this->tree->sponsorMap());

        $this->assertCount(2, $uplines);
        $this->assertSame([$s2->id, 1, 1], [$uplines[0]['id'], $uplines[0]['level'], $uplines[0]['depth']]);
        // Level 2 but depth 3: the skipped inactive member is the difference.
        $this->assertSame([$shiva->id, 2, 3], [$uplines[1]['id'], $uplines[1]['level'], $uplines[1]['depth']]);
        $this->assertNotContains($s1->id, array_column($uplines, 'id'));
    }

    #[Test]
    public function the_cap_is_five_active_levels_not_five_database_hops(): void
    {
        // Eight sponsors above the seller, three of them inactive. The walk must
        // still find FIVE active recipients by reaching further up.
        $members = $this->chain([true, true, false, true, false, true, false, true, true]);
        $seller = end($members);

        $uplines = $this->tree->eligibleUplines($seller->id, $this->tree->sponsorMap());

        $this->assertCount(5, $uplines);
        $this->assertSame([1, 2, 3, 4, 5], array_column($uplines, 'level'));
        // The deepest recipient sits further away than five hops.
        $this->assertGreaterThan(5, $uplines[4]['depth']);
    }

    #[Test]
    public function more_than_five_active_sponsors_stops_at_five(): void
    {
        $members = $this->chain([true, true, true, true, true, true, true, true]);
        $seller = end($members);

        $uplines = $this->tree->eligibleUplines($seller->id, $this->tree->sponsorMap());

        $this->assertCount(5, $uplines);
        // The two members above the fifth level get nothing.
        $this->assertNotContains($members[0]->id, array_column($uplines, 'id'));
        $this->assertNotContains($members[1]->id, array_column($uplines, 'id'));
    }

    // -----------------------------------------------------------------
    // Sales eligibility
    // -----------------------------------------------------------------

    #[Test]
    public function an_inactive_sellers_sales_are_excluded_from_the_pool(): void
    {
        [$shiva, $active] = $this->chain([true, true]);
        $inactive = Member::factory()->inactive()->sponsoredBy($shiva)->create();

        $this->sell($active, '1000.00');
        $this->sell($inactive, '5000.00');

        $result = $this->calculator->compute($this->period());

        // Only the active seller's 1,000 counts.
        $this->assertSame('1000.00', $result['total_sqft']);
        $this->assertSame('50000.00', $result['pool_amount']);
        $this->assertSame(1, $result['seller_count']);

        // And the exclusion is reported, not hidden.
        $this->assertSame(1, $result['excluded_seller_count']);
        $this->assertSame('5000.00', $result['excluded_sqft']);
    }

    #[Test]
    public function an_inactive_seller_generates_no_eligibility_for_their_uplines(): void
    {
        [$shiva, $middle] = $this->chain([true, true]);
        $inactive = Member::factory()->inactive()->sponsoredBy($middle)->create();

        $this->sell($inactive, '5000.00');

        $result = $this->calculator->compute($this->period());

        $this->assertSame(0, $result['eligible_count']);
        $this->assertSame([], $result['paths']);
    }

    #[Test]
    public function the_historical_sale_of_an_inactive_seller_remains_visible(): void
    {
        $shiva = Member::factory()->root()->create();
        $inactive = Member::factory()->inactive()->sponsoredBy($shiva)->create();
        $sale = $this->sell($inactive, '5000.00');

        $this->calculator->compute($this->period());

        // Excluded from the calculation, but the sale itself is untouched.
        $this->assertDatabaseHas('registry_sales', ['id' => $sale->id, 'sqft' => '5000.00']);
    }

    #[Test]
    public function a_direct_company_club_members_sale_counts_toward_the_pool(): void
    {
        $shiva = Member::factory()->root()->create();
        $this->sell($shiva, '1000.00');

        $result = $this->calculator->compute($this->period());

        $this->assertSame('1000.00', $result['total_sqft']);
        $this->assertSame('50000.00', $result['pool_amount']);
    }

    #[Test]
    public function a_direct_company_club_member_produces_no_upline_recipient(): void
    {
        $shiva = Member::factory()->root()->create();
        $this->sell($shiva, '1000.00');

        $result = $this->calculator->compute($this->period());

        // The pool exists; nobody is above Shiva, and the Club is not a payout
        // member, so nothing is distributed.
        $this->assertSame('50000.00', $result['pool_amount']);
        $this->assertSame(0, $result['eligible_count']);
        $this->assertSame('0.00', $result['distributed_amount']);

        // The invariant still holds: distributed = pool + residual.
        $this->assertSame('-50000.00', $result['residual_amount']);
        $this->assertSame(
            $result['distributed_amount'],
            Money::add($result['pool_amount'], $result['residual_amount']),
        );
    }

    // -----------------------------------------------------------------
    // Pool and distribution
    // -----------------------------------------------------------------

    #[Test]
    public function the_monthly_total_sums_every_eligible_sale(): void
    {
        [$shiva, $a] = $this->chain([true, true]);
        $b = Member::factory()->sponsoredBy($shiva)->create();

        $this->sell($a, '1000.50');
        $this->sell($a, '2000.25');
        $this->sell($b, '3000.25');

        $result = $this->calculator->compute($this->period());

        $this->assertSame('6001.00', $result['total_sqft']);
    }

    #[Test]
    public function the_pool_is_the_total_multiplied_by_fifty(): void
    {
        [$shiva, $seller] = $this->chain([true, true]);
        $this->sell($seller, '50000.00');

        $result = $this->calculator->compute($this->period());

        $this->assertSame('50.00', $result['rate']);
        $this->assertSame('2500000.00', $result['pool_amount']);
    }

    #[Test]
    public function there_is_one_pool_for_the_month_not_one_per_seller(): void
    {
        [$shiva, $a] = $this->chain([true, true]);
        $b = Member::factory()->sponsoredBy($shiva)->create();
        $c = Member::factory()->sponsoredBy($shiva)->create();

        $this->sell($a, '1000.00');
        $this->sell($b, '1000.00');
        $this->sell($c, '1000.00');

        $result = $this->calculator->compute($this->period());

        // 3,000 x 50 = 150,000 once - NOT three pools of 50,000 each.
        $this->assertSame('3000.00', $result['total_sqft']);
        $this->assertSame('150000.00', $result['pool_amount']);

        // One recipient (Shiva), who receives the whole single pool.
        $this->assertSame(1, $result['eligible_count']);
        $this->assertSame('150000.00', $result['equal_share']);
    }

    #[Test]
    public function the_pool_is_divided_equally_among_unique_recipients(): void
    {
        // The specification's acceptance case: 50,000 Sq.Ft. and 10 recipients.
        $chain = $this->chain(array_fill(0, 11, true));
        $seller = end($chain);

        // Five above the seller qualify; add five more sellers deeper in other
        // branches so ten distinct members end up eligible.
        $this->sell($seller, '50000.00');

        $result = $this->calculator->compute($this->period());

        $this->assertSame('2500000.00', $result['pool_amount']);
        $this->assertSame(5, $result['eligible_count']);
        $this->assertSame('500000.00', $result['equal_share']);
        $this->assertSame('2500000.00', $result['distributed_amount']);
        $this->assertSame('0.00', $result['residual_amount']);
    }

    #[Test]
    public function the_specification_acceptance_case_reproduces_exactly(): void
    {
        // 50,000 Sq.Ft. and exactly 10 unique recipients -> 2,50,000 each.
        // Ten separate branches of two, each branch's leader qualifying once.
        $sellers = [];
        $leaders = [];

        for ($i = 0; $i < 10; $i++) {
            $leader = Member::factory()->root()->create();
            $seller = Member::factory()->sponsoredBy($leader)->create();
            $leaders[] = $leader;
            $sellers[] = $seller;
        }

        foreach ($sellers as $seller) {
            $this->sell($seller, '5000.00');
        }

        $result = $this->calculator->compute($this->period());

        $this->assertSame('50000.00', $result['total_sqft']);
        $this->assertSame('2500000.00', $result['pool_amount']);
        $this->assertSame(10, $result['eligible_count']);
        $this->assertSame('250000.00', $result['equal_share']);
        $this->assertSame('2500000.00', $result['distributed_amount']);
        $this->assertSame('0.00', $result['residual_amount']);
    }

    #[Test]
    public function a_member_qualifying_through_several_branches_is_paid_only_once(): void
    {
        //        shiva
        //        /   \
        //      s1     s3
        //      |       |
        //     s2*     s4*      (* = sells)
        $shiva = Member::factory()->root()->create();
        $s1 = Member::factory()->sponsoredBy($shiva)->create();
        $s2 = Member::factory()->sponsoredBy($s1)->create();
        $s3 = Member::factory()->sponsoredBy($shiva)->create();
        $s4 = Member::factory()->sponsoredBy($s3)->create();

        $this->sell($s2, '1000.00');
        $this->sell($s4, '1000.00');

        $result = $this->calculator->compute($this->period());

        $shivaRow = collect($result['recipients'])->firstWhere('member_id', $shiva->id);

        // ONE payout...
        $this->assertSame(
            1,
            collect($result['recipients'])->where('member_id', $shiva->id)->count(),
        );
        // ...with BOTH reasons preserved.
        $this->assertSame(2, $shivaRow['path_count']);

        $shivaPaths = collect($result['paths'])->where('eligible_member_id', $shiva->id);
        $this->assertSame(2, $shivaPaths->count());
        $this->assertEqualsCanonicalizing(
            [$s2->id, $s4->id],
            $shivaPaths->pluck('sale_member_id')->all(),
        );

        // Recipients are shiva, s1 and s3 - four members, three paid.
        $this->assertSame(3, $result['eligible_count']);
    }

    #[Test]
    public function a_share_that_does_not_divide_evenly_keeps_two_decimals_and_reports_the_residual(): void
    {
        //  3 recipients, pool 50,000 -> 16,666.67 each, one paisa over.
        $shiva = Member::factory()->root()->create();
        $a = Member::factory()->sponsoredBy($shiva)->create();
        $b = Member::factory()->sponsoredBy($a)->create();
        $seller = Member::factory()->sponsoredBy($b)->create();

        $this->sell($seller, '1000.00');

        $result = $this->calculator->compute($this->period());

        $this->assertSame('50000.00', $result['pool_amount']);
        $this->assertSame(3, $result['eligible_count']);
        $this->assertSame('16666.67', $result['equal_share']);
        $this->assertSame('50000.01', $result['distributed_amount']);
        $this->assertSame('0.01', $result['residual_amount']);

        // Two decimal places, never more.
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $result['equal_share']);
    }

    // -----------------------------------------------------------------
    // Preview vs. calculation
    // -----------------------------------------------------------------

    #[Test]
    public function preview_creates_no_ledger_entry_and_no_run(): void
    {
        [$shiva, $seller] = $this->chain([true, true]);
        $this->sell($seller, '1000.00');

        $preview = $this->club->preview($this->period());

        $this->assertSame('50000.00', $preview['pool_amount']);
        $this->assertSame(1, $preview['eligible_count']);

        $this->assertDatabaseCount('reward_ledger', 0);
        $this->assertDatabaseCount('company_club_rewards', 0);
        $this->assertDatabaseCount('company_club_calculation_runs', 0);
        $this->assertDatabaseCount('company_club_eligibility_paths', 0);
        $this->assertFalse($preview['calculated']);
    }

    #[Test]
    public function the_calculation_service_cannot_write_at_all(): void
    {
        // Structural: the object that computes has no route to the ledger.
        $source = $this->codeWithoutComments('Services/CompanyClubCalculationService.php');

        foreach (['RewardLedger', 'CompanyClubReward::', 'insert(', 'DB::transaction', '->save('] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                "CompanyClubCalculationService must not be able to write [{$forbidden}].",
            );
        }
    }

    #[Test]
    public function the_final_calculation_creates_the_ledger(): void
    {
        [$shiva, $seller] = $this->chain([true, true]);
        $this->sell($seller, '1000.00');

        $run = $this->club->calculate($this->period(), $this->admin);

        $this->assertSame('50000.00', (string) $run->pool_amount);
        $this->assertSame(1, (int) $run->eligible_count);

        $this->assertDatabaseHas('reward_ledger', [
            'member_id' => $shiva->id,
            'reward_type' => RewardType::CompanyClub->value,
            'source_type' => CompanyClubService::SOURCE_TYPE,
            'period' => $this->period(),
            'amount' => '50000.00',
            'rate' => '50.00',
            'status' => LedgerStatus::Posted->value,
        ]);

        $this->assertDatabaseHas('company_club_rewards', [
            'company_club_run_id' => $run->id,
            'member_id' => $shiva->id,
            'amount' => '50000.00',
        ]);
    }

    #[Test]
    public function preview_and_the_real_calculation_agree(): void
    {
        [$shiva, $a, $b] = $this->chain([true, true, true]);
        $this->sell($b, '1234.56');

        $preview = $this->club->preview($this->period());
        $run = $this->club->calculate($this->period(), $this->admin);

        $this->assertSame($preview['total_sqft'], (string) $run->total_sqft);
        $this->assertSame($preview['pool_amount'], (string) $run->pool_amount);
        $this->assertSame($preview['eligible_count'], (int) $run->eligible_count);
        $this->assertSame($preview['equal_share'], (string) $run->equal_share);
    }

    // -----------------------------------------------------------------
    // Runs, duplicates and history
    // -----------------------------------------------------------------

    #[Test]
    public function a_second_calculation_of_the_same_period_is_refused(): void
    {
        [$shiva, $seller] = $this->chain([true, true]);
        $this->sell($seller, '1000.00');

        $this->club->calculate($this->period(), $this->admin);

        $this->expectException(RuntimeException::class);
        $this->club->calculate($this->period(), $this->admin);
    }

    #[Test]
    public function a_refused_duplicate_leaves_the_ledger_untouched(): void
    {
        [$shiva, $seller] = $this->chain([true, true]);
        $this->sell($seller, '1000.00');

        $this->club->calculate($this->period(), $this->admin);
        $before = RewardLedger::query()->ofType(RewardType::CompanyClub)->count();

        try {
            $this->club->calculate($this->period(), $this->admin);
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame($before, RewardLedger::query()->ofType(RewardType::CompanyClub)->count());
    }

    #[Test]
    public function the_database_refuses_two_company_club_rewards_for_one_member_in_one_month(): void
    {
        [$shiva, $seller] = $this->chain([true, true]);
        $this->sell($seller, '1000.00');
        $run = $this->club->calculate($this->period(), $this->admin);

        $this->expectException(QueryException::class);

        // Bypassing the engine entirely: the unique index is the real guard.
        RewardLedger::insert([
            'member_id' => $shiva->id,
            'reward_type' => RewardType::CompanyClub->value,
            'source_type' => CompanyClubService::SOURCE_TYPE,
            'source_id' => CompanyClubService::SOURCE_ID,
            'period' => $this->period(),
            'sqft' => '1000.00',
            'rate' => '50.00',
            'amount' => '50000.00',
            'status' => LedgerStatus::Posted->value,
            'calculation_run_id' => $run->calculation_run_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function every_run_gets_a_unique_sequential_code(): void
    {
        [$shiva, $seller] = $this->chain([true, true]);
        $this->sell($seller, '1000.00');

        $first = $this->club->calculate($this->period(), $this->admin);
        $second = $this->club->recalculate($this->period(), $this->admin);
        $third = $this->club->recalculate($this->period(), $this->admin);

        $this->assertSame('CC-'.$this->period().'-0001', $first->run_code);
        $this->assertSame('CC-'.$this->period().'-0002', $second->run_code);
        $this->assertSame('CC-'.$this->period().'-0003', $third->run_code);
    }

    #[Test]
    public function a_previous_calculation_stays_available_after_recalculation(): void
    {
        [$shiva, $seller] = $this->chain([true, true]);
        $this->sell($seller, '1000.00');

        $first = $this->club->calculate($this->period(), $this->admin);
        $this->assertSame('50000.00', (string) $first->pool_amount);

        // A later sale changes the month.
        $this->sell($seller, '1000.00');
        $second = $this->club->recalculate($this->period(), $this->admin);

        $this->assertSame('100000.00', (string) $second->pool_amount);

        // The earlier run is still there, with its own figures, marked superseded.
        $first->refresh();
        $this->assertSame(CalculationRunStatus::Superseded, $first->status);
        $this->assertSame('50000.00', (string) $first->pool_amount);
        $this->assertSame(1, (int) $first->eligible_count);

        // And the history screen can see both.
        $history = $this->club->history($this->period());
        $this->assertCount(2, $history);
    }

    #[Test]
    public function recalculation_replaces_the_ledger_rather_than_duplicating_it(): void
    {
        [$shiva, $seller] = $this->chain([true, true]);
        $this->sell($seller, '1000.00');

        $this->club->calculate($this->period(), $this->admin);
        $this->club->recalculate($this->period(), $this->admin);
        $this->club->recalculate($this->period(), $this->admin);

        $this->assertSame(
            1,
            RewardLedger::query()->ofType(RewardType::CompanyClub)->count(),
        );
        $this->assertSame(1, CompanyClubReward::query()->count());
    }

    #[Test]
    public function a_paid_month_refuses_to_recalculate(): void
    {
        [$shiva, $seller] = $this->chain([true, true]);
        $this->sell($seller, '1000.00');
        $this->club->calculate($this->period(), $this->admin);

        RewardLedger::query()->update(['status' => LedgerStatus::Paid]);

        $this->expectException(RuntimeException::class);
        $this->club->recalculate($this->period(), $this->admin);
    }

    #[Test]
    public function a_month_that_was_never_calculated_is_not_calculated_automatically(): void
    {
        [$shiva, $seller] = $this->chain([true, true]);
        $this->sell($seller, '1000.00');

        $result = $this->club->recalculateIfCalculated($this->period(), $this->admin);

        $this->assertNull($result);
        $this->assertDatabaseCount('company_club_calculation_runs', 0);
        $this->assertDatabaseCount('reward_ledger', 0);
    }

    #[Test]
    public function an_already_calculated_month_is_rebuilt_automatically(): void
    {
        [$shiva, $seller] = $this->chain([true, true]);
        $this->sell($seller, '1000.00');
        $this->club->calculate($this->period(), $this->admin);

        $this->sell($seller, '500.00');
        $run = $this->club->recalculateIfCalculated($this->period(), $this->admin);

        $this->assertNotNull($run);
        $this->assertSame('75000.00', (string) $run->pool_amount);
        $this->assertTrue($run->automatic);
    }

    #[Test]
    public function a_month_out_of_step_with_its_sales_is_reported(): void
    {
        [$shiva, $seller] = $this->chain([true, true]);
        $this->sell($seller, '1000.00');
        $this->club->calculate($this->period(), $this->admin);

        $this->assertFalse($this->club->needsRecalculation($this->period()));

        $this->sell($seller, '500.00');

        $this->assertTrue($this->club->needsRecalculation($this->period()));
    }

    // -----------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------

    #[Test]
    public function the_club_display_name_is_configurable_and_changes_no_figure(): void
    {
        [$shiva, $seller] = $this->chain([true, true]);
        $this->sell($seller, '1000.00');

        $before = $this->calculator->compute($this->period());

        $this->club->updateSettings(['display_name' => 'Corporate Club']);

        $after = $this->calculator->compute($this->period());

        $this->assertSame('Corporate Club', CompanyClubSetting::current()->name());
        $this->assertSame($before['pool_amount'], $after['pool_amount']);
        $this->assertSame($before['eligible_count'], $after['eligible_count']);
    }

    #[Test]
    public function editing_the_rate_does_not_rewrite_a_recorded_run(): void
    {
        [$shiva, $seller] = $this->chain([true, true]);
        $this->sell($seller, '1000.00');

        $run = $this->club->calculate($this->period(), $this->admin);
        $this->assertSame('50.00', (string) $run->rate);

        $this->club->updateSettings(['reward_rate' => '75.00']);

        $run->refresh();
        $this->assertSame('50.00', (string) $run->rate);
        $this->assertSame('50000.00', (string) $run->pool_amount);
        $this->assertDatabaseHas('reward_ledger', ['rate' => '50.00', 'amount' => '50000.00']);
    }

    #[Test]
    public function the_level_cap_is_configurable(): void
    {
        $members = $this->chain(array_fill(0, 9, true));
        $seller = end($members);

        $this->club->updateSettings(['max_upline_levels' => 3]);

        $uplines = $this->tree->eligibleUplines($seller->id, $this->tree->sponsorMap());

        $this->assertCount(3, $uplines);
    }

    // -----------------------------------------------------------------
    // Independence from the existing engines
    // -----------------------------------------------------------------

    #[Test]
    public function the_company_club_walk_agrees_with_the_upline_walk_today(): void
    {
        /*
         * The two engines implement the same eligibility rule and the code is
         * deliberately duplicated so that changing one cannot silently move the
         * other's money. This test is the tripwire: if the rules ever diverge it
         * fails, and somebody makes a decision instead of discovering the drift
         * in a payout.
         */
        $members = $this->chain([true, true, false, true, true, false, true, true, true]);
        $seller = end($members);

        $clubNetwork = $this->tree->sponsorMap();
        $club = $this->tree->eligibleUplines($seller->id, $clubNetwork);

        $uplineService = app(UplineRewardService::class);
        $uplineNetwork = [];
        foreach ($clubNetwork as $id => $node) {
            $uplineNetwork[$id] = $node;
        }
        $upline = $uplineService->eligibleUplines($seller->id, $uplineNetwork);

        $this->assertSame(
            array_column($upline, 'id'),
            array_column($club, 'id'),
            'The Company Club walk and the Upline walk have diverged. '
            .'This is a deliberate tripwire - decide which is correct rather than deleting the test.',
        );
    }

    /**
     * A file's CODE, with all comments removed.
     *
     * The structural tests below assert what the engine can actually reach.
     * Reading the raw file would fail on a docblock that merely NAMES another
     * class - and these classes deliberately explain in prose why they do not
     * call the upline engine, so the explanation would break the test that the
     * explanation is about.
     */
    private function codeWithoutComments(string $relativePath): string
    {
        $tokens = token_get_all(file_get_contents(app_path($relativePath)));

        $code = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }

    #[Test]
    public function the_engine_depends_on_no_other_reward_engine(): void
    {
        foreach ([
            'Services/CompanyClubService.php',
            'Services/CompanyClubCalculationService.php',
            'Services/CompanyClubTreeService.php',
        ] as $file) {
            $source = $this->codeWithoutComments($file);

            foreach ([
                'UplineRewardService',
                'DirectRewardService',
                'TargetRewardService',
                'TeamSalesService',
                'UplineCalculation',
                'TargetCalculation',
                'TeamCalculation',
            ] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $source,
                    "{$file} must not depend on {$forbidden}.",
                );
            }
        }
    }

    #[Test]
    public function running_company_club_leaves_the_other_engines_untouched(): void
    {
        [$shiva, $seller] = $this->chain([true, true]);
        $this->sell($seller, '1000.00');

        $direct = app(DirectRewardService::class);
        $upline = app(UplineRewardService::class);

        $direct->calculate($this->period(), $this->admin);
        $upline->calculate($this->period(), $this->admin);

        $before = RewardLedger::query()
            ->whereIn('reward_type', [RewardType::Direct->value, RewardType::Upline->value])
            ->orderBy('id')
            ->get(['member_id', 'reward_type', 'sqft', 'rate', 'amount'])
            ->toArray();

        $this->club->calculate($this->period(), $this->admin);

        $after = RewardLedger::query()
            ->whereIn('reward_type', [RewardType::Direct->value, RewardType::Upline->value])
            ->orderBy('id')
            ->get(['member_id', 'reward_type', 'sqft', 'rate', 'amount'])
            ->toArray();

        $this->assertSame($before, $after);
    }

    #[Test]
    public function the_company_club_total_may_differ_from_the_direct_total_when_a_seller_is_inactive(): void
    {
        // The one place this engine deliberately disagrees with the others.
        $shiva = Member::factory()->root()->create();
        $active = Member::factory()->sponsoredBy($shiva)->create();
        $inactive = Member::factory()->inactive()->sponsoredBy($shiva)->create();

        $this->sell($active, '1000.00');
        $this->sell($inactive, '4000.00');

        $directRun = app(DirectRewardService::class)
            ->calculate($this->period(), $this->admin);
        $clubRun = $this->club->calculate($this->period(), $this->admin);

        // Direct counts everything...
        $this->assertSame('5000.00', (string) $directRun->total_sqft);
        // ...Company Club counts only the active seller. Both are correct.
        $this->assertSame('1000.00', (string) $clubRun->total_sqft);
    }

    // -----------------------------------------------------------------
    // Reconciliation and audit
    // -----------------------------------------------------------------

    #[Test]
    public function the_run_totals_reconcile_to_the_ledger(): void
    {
        $shiva = Member::factory()->root()->create();
        $a = Member::factory()->sponsoredBy($shiva)->create();
        $b = Member::factory()->sponsoredBy($a)->create();

        $this->sell($b, '3333.33');

        $run = $this->club->calculate($this->period(), $this->admin);

        $ledgerTotal = RewardLedger::query()
            ->ofType(RewardType::CompanyClub)
            ->sum('amount');

        $this->assertSame(
            (string) $run->distributed_amount,
            Money::of($ledgerTotal),
        );
        $this->assertSame(
            (int) $run->eligible_count,
            RewardLedger::query()->ofType(RewardType::CompanyClub)->count(),
        );
    }

    #[Test]
    public function every_eligibility_path_is_stored_with_its_walk(): void
    {
        // Shiva ACTIVE -> s1 INACTIVE -> s2 ACTIVE (sells)
        [$shiva, $s1, $s2] = $this->chain([true, false, true]);
        $this->sell($s2, '1000.00');

        $run = $this->club->calculate($this->period(), $this->admin);

        $path = CompanyClubEligibilityPath::query()
            ->where('company_club_run_id', $run->id)
            ->where('eligible_member_id', $shiva->id)
            ->firstOrFail();

        $this->assertSame($s2->id, $path->sale_member_id);
        $this->assertSame(1, $path->upline_level);
        $this->assertSame(2, $path->chain_depth);
        $this->assertTrue($path->skippedInactive());

        // The snapshot records the skipped member by name, for the audit trail.
        $snapshot = collect($path->path_snapshot);
        $this->assertSame(2, $snapshot->count());
        $this->assertSame('skipped-inactive', $snapshot->firstWhere('id', $s1->id)['outcome']);
        $this->assertSame('eligible', $snapshot->firstWhere('id', $shiva->id)['outcome']);
    }

    #[Test]
    public function a_month_with_no_sales_calculates_to_an_empty_run(): void
    {
        $this->chain([true, true]);

        $run = $this->club->calculate($this->period(), $this->admin);

        $this->assertSame('0.00', (string) $run->total_sqft);
        $this->assertSame('0.00', (string) $run->pool_amount);
        $this->assertSame(0, (int) $run->eligible_count);
        $this->assertDatabaseCount('reward_ledger', 0);
    }

    #[Test]
    public function periods_are_calculated_independently(): void
    {
        [$shiva, $seller] = $this->chain([true, true]);

        $earlier = now()->subMonths(2)->format('Y-m');
        $later = now()->subMonth()->format('Y-m');

        $this->sell($seller, '1000.00', $earlier);
        $this->sell($seller, '2000.00', $later);

        $runA = $this->club->calculate($earlier, $this->admin);
        $runB = $this->club->calculate($later, $this->admin);

        $this->assertSame('50000.00', (string) $runA->pool_amount);
        $this->assertSame('100000.00', (string) $runB->pool_amount);
        $this->assertSame('CC-'.$earlier.'-0001', $runA->run_code);
        $this->assertSame('CC-'.$later.'-0001', $runB->run_code);
    }

    #[Test]
    public function a_future_period_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->club->preview(now()->addMonth()->format('Y-m'));
    }

    // -----------------------------------------------------------------
    // The automatic rebuild, wired through PeriodRecalculationService
    // -----------------------------------------------------------------

    #[Test]
    public function a_full_period_rebuild_leaves_an_uncalculated_month_alone(): void
    {
        [$shiva, $seller] = $this->chain([true, true]);
        $this->sell($seller, '1000.00');

        app(PeriodRecalculationService::class)->recalculate($this->period(), $this->admin);

        // The other four engines ran...
        $this->assertDatabaseHas('reward_ledger', ['reward_type' => RewardType::Direct->value]);
        // ...and Company Club deliberately did not, because no admin has ever
        // committed to this month.
        $this->assertDatabaseCount('company_club_calculation_runs', 0);
        $this->assertSame(
            0,
            RewardLedger::query()->ofType(RewardType::CompanyClub)->count(),
        );
    }

    #[Test]
    public function a_full_period_rebuild_refreshes_an_already_calculated_month(): void
    {
        [$shiva, $seller] = $this->chain([true, true]);
        $this->sell($seller, '1000.00');

        $first = $this->club->calculate($this->period(), $this->admin);
        $this->assertSame('50000.00', (string) $first->pool_amount);

        // A later sale arrives, and the whole month is rebuilt.
        $this->sell($seller, '1000.00');
        app(PeriodRecalculationService::class)->recalculate($this->period(), $this->admin);

        $live = $this->club->latestRun($this->period());

        $this->assertNotNull($live);
        $this->assertNotSame($first->id, $live->id);
        $this->assertSame('100000.00', (string) $live->pool_amount);
        $this->assertTrue($live->automatic);

        // Still exactly one live reward, not two.
        $this->assertSame(
            1,
            RewardLedger::query()->ofType(RewardType::CompanyClub)->count(),
        );
    }

    #[Test]
    public function entering_a_sale_rebuilds_an_already_calculated_company_club_month(): void
    {
        [$shiva, $seller] = $this->chain([true, true]);
        $sale = $this->sell($seller, '1000.00');

        $this->club->calculate($this->period(), $this->admin);

        $second = $this->sell($seller, '500.00');
        app(PeriodRecalculationService::class)->afterSale($second, $this->admin);

        $this->assertSame(
            '75000.00',
            (string) $this->club->latestRun($this->period())->pool_amount,
        );
        $this->assertFalse($this->club->needsRecalculation($this->period()));
    }

    #[Test]
    public function a_paid_company_club_month_stands_still_while_the_other_engines_rebuild(): void
    {
        /*
         * Client-confirmed 2026-09-01, replacing the rule this test used to
         * pin. A paid Company Club share once took the whole month down with
         * it: Direct could not follow its own sales because money had gone out
         * of an unrelated engine.
         *
         * The Club share is still never rewritten — that is the point of the
         * lock and it is unchanged. What changed is that it no longer reaches
         * across into engines nobody has been paid from.
         */
        [$shiva, $seller] = $this->chain([true, true]);
        $this->sell($seller, '1000.00');
        $this->club->calculate($this->period(), $this->admin);

        $clubBefore = (string) RewardLedger::query()
            ->ofType(RewardType::CompanyClub)
            ->sum('amount');

        RewardLedger::query()
            ->ofType(RewardType::CompanyClub)
            ->update(['status' => LedgerStatus::Paid]);

        // A late sale into the same month.
        $this->sell($seller, '500.00');

        $outcome = app(PeriodRecalculationService::class)->recalculate($this->period(), $this->admin);

        // Direct followed the sale: 1,500 x 40.
        $this->assertSame('60000.00', (string) RewardLedger::query()
            ->ofType(RewardType::Direct)
            ->sum('amount'));

        // The paid Club share is exactly as it was, and said to be so.
        $this->assertSame($clubBefore, (string) RewardLedger::query()
            ->ofType(RewardType::CompanyClub)
            ->sum('amount'));
        $this->assertStringContainsString('Company Club', implode(' ', $outcome['locked']));
    }

    #[Test]
    public function a_month_still_running_cannot_be_calculated(): void
    {
        /*
         * Client-confirmed 2026-09-01, and unique to this engine. A Club share
         * is the pool DIVIDED between the eligible members, so a member joining
         * the eligible list on the 25th cuts what everybody else receives.
         * Committing mid-month publishes an amount certain to fall.
         *
         * Preview is deliberately still open — it writes nothing.
         */
        [$shiva, $seller] = $this->chain([true, true]);
        $current = now()->format('Y-m');
        $this->sell($seller, '1000.00', $current);

        // Preview works and shows real figures.
        $preview = $this->club->preview($current);
        $this->assertFalse($preview['month_is_over']);
        $this->assertGreaterThan(0, $preview['eligible_count']);

        // Committing does not.
        $this->assertNotNull($this->club->calculationBlockedReason($current));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has not finished');

        $this->club->calculate($current, $this->admin);
    }

    #[Test]
    public function a_month_that_has_ended_can_be_calculated(): void
    {
        [$shiva, $seller] = $this->chain([true, true]);
        $this->sell($seller, '1000.00');

        $this->assertNull($this->club->calculationBlockedReason($this->period()));

        $run = $this->club->calculate($this->period(), $this->admin);

        $this->assertSame($this->period(), $run->period);
    }
}

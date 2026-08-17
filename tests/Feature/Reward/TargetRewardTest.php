<?php

namespace Tests\Feature\Reward;

use App\Enums\CalculationRunType;
use App\Enums\MemberStatus;
use App\Enums\RewardType;
use App\Models\Member;
use App\Models\RegistrySale;
use App\Models\RewardLedger;
use App\Models\TargetCalculation;
use App\Models\User;
use App\Services\TargetRewardService;
use App\Services\TeamSalesService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Target 1 — one calendar month, 5,000 Sq.Ft., ₹150,000.
 *
 * Every rule confirmed by the client on 2026-08-17
 * (docs/02_BUSINESS_RULES.md §3.1) has a test here, including the ones that are
 * defined by what does NOT happen: a surplus is not paid, a miss is not
 * penalised, and a member never achieves the same target twice.
 */
class TargetRewardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private TargetRewardService $service;

    private TeamSalesService $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->service = app(TargetRewardService::class);
        $this->team = app(TeamSalesService::class);
    }

    private function sell(Member $member, string $sqft, string $period = '2026-06'): void
    {
        RegistrySale::factory()->forMember($member)->sqft($sqft)->inPeriod($period)->create();
    }

    /** Team Sales is the input to the Target engine, so it always runs first. */
    private function runTargets(string $period = '2026-06'): void
    {
        $this->team->calculate($period, $this->admin);
        $this->service->calculate($period, $this->admin);
    }

    private function verdictFor(Member $member, string $period = '2026-06'): ?TargetCalculation
    {
        return TargetCalculation::where('member_id', $member->id)
            ->where('period', $period)
            ->first();
    }

    // -----------------------------------------------------------------
    // The acceptance case
    // -----------------------------------------------------------------

    #[Test]
    public function exactly_five_thousand_sqft_achieves_the_target_and_pays_150000(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '5000.00');

        $this->runTargets();

        $verdict = $this->verdictFor($member);

        $this->assertNotNull($verdict);
        $this->assertTrue($verdict->achieved);
        $this->assertSame('5000.00', $verdict->achieved_sqft);
        $this->assertSame('5000.00', $verdict->target_sqft);
        $this->assertSame('150000.00', $verdict->reward_amount);
        $this->assertSame('0.00', $verdict->shortfall_sqft);
    }

    #[Test]
    public function the_threshold_is_inclusive_but_one_sqft_below_it_is_a_miss(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '4999.99');

        $this->runTargets();

        $verdict = $this->verdictFor($member);

        $this->assertFalse($verdict->achieved);
        $this->assertSame('0.01', $verdict->shortfall_sqft);
        $this->assertSame('0.00', $verdict->reward_amount);
    }

    // -----------------------------------------------------------------
    // Rule 2 — the reward is fixed at the threshold, never scaled
    // -----------------------------------------------------------------

    #[Test]
    public function seven_thousand_against_five_thousand_still_pays_only_the_threshold(): void
    {
        // The client's own example: "if team does 7000 against 5000 yet target
        // 5000 sqft is calculated."
        $leader = Member::factory()->create();
        $downline = Member::factory()->sponsoredBy($leader)->create();

        $this->sell($leader, '3000.00');
        $this->sell($downline, '4000.00');

        $this->runTargets();

        $verdict = $this->verdictFor($leader);

        $this->assertTrue($verdict->achieved);
        $this->assertSame('7000.00', $verdict->achieved_sqft);

        // 5,000 × 30, NOT 7,000 × 30 = 210,000.
        $this->assertSame('150000.00', $verdict->reward_amount);
        $this->assertNotSame('210000.00', $verdict->reward_amount);

        // The surplus is visible but explicitly unpaid.
        $this->assertSame('2000.00', $verdict->surplusSqft());
    }

    #[Test]
    public function the_ledger_row_records_the_threshold_sqft_so_sqft_times_rate_equals_amount(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '9000.00');

        $this->runTargets();

        $entry = RewardLedger::where('member_id', $member->id)
            ->where('reward_type', RewardType::Target)
            ->firstOrFail();

        // 9,000 was sold, but 5,000 is what was paid on.
        $this->assertSame('5000.00', $entry->sqft);
        $this->assertSame('30.00', $entry->rate);
        $this->assertSame('150000.00', $entry->amount);
        $this->assertSame(
            $entry->amount,
            bcmul($entry->sqft, $entry->rate, 2),
            'sqft x rate must reconcile to amount on every target ledger row.'
        );
    }

    #[Test]
    public function the_surplus_is_discarded_and_never_carries_into_the_next_month(): void
    {
        $member = Member::factory()->create();

        // 8,000 in June — 3,000 above the threshold.
        $this->sell($member, '8000.00', '2026-06');
        $this->runTargets('2026-06');

        $this->assertSame('3000.00', $this->verdictFor($member, '2026-06')->surplusSqft());

        // July starts from zero: 2,000 sold is 2,000 measured, not 5,000.
        $this->sell($member, '2000.00', '2026-07');
        $this->team->calculate('2026-07', $this->admin);
        $this->service->calculate('2026-07', $this->admin);

        // They already achieved Target 1, so they are no longer measured at all.
        $this->assertNull($this->verdictFor($member, '2026-07'));
    }

    // -----------------------------------------------------------------
    // Rule 1 — the period is a calendar month, 1st to last day
    // -----------------------------------------------------------------

    #[Test]
    public function a_member_who_joined_mid_month_gets_the_full_threshold_not_a_prorated_one(): void
    {
        // Joined on the 20th with 9 days of the month left.
        $member = Member::factory()->create(['joining_date' => '2026-06-20']);
        $this->sell($member, '4000.00', '2026-06');

        $this->runTargets('2026-06');

        $verdict = $this->verdictFor($member);

        // The threshold is not reduced for the short first month.
        $this->assertSame('5000.00', $verdict->target_sqft);
        $this->assertFalse($verdict->achieved);
        $this->assertSame('1000.00', $verdict->shortfall_sqft);
    }

    #[Test]
    public function sales_are_counted_per_calendar_month_and_never_across_a_rolling_window(): void
    {
        $member = Member::factory()->create();

        // 3,000 at the end of June and 3,000 at the start of July is 6,000
        // within any 30-day rolling window — but neither month reaches 5,000.
        RegistrySale::factory()->forMember($member)->sqft('3000.00')
            ->create(['registry_date' => '2026-06-30', 'sale_date' => '2026-06-30']);
        RegistrySale::factory()->forMember($member)->sqft('3000.00')
            ->create(['registry_date' => '2026-07-01', 'sale_date' => '2026-07-01']);

        $this->runTargets('2026-06');
        $this->team->calculate('2026-07', $this->admin);
        $this->service->calculate('2026-07', $this->admin);

        $this->assertFalse($this->verdictFor($member, '2026-06')->achieved);
        $this->assertFalse($this->verdictFor($member, '2026-07')->achieved);
        $this->assertSame(0, RewardLedger::where('reward_type', RewardType::Target)->count());
    }

    #[Test]
    public function the_first_and_last_day_of_the_month_both_count(): void
    {
        $member = Member::factory()->create();

        RegistrySale::factory()->forMember($member)->sqft('2500.00')
            ->create(['registry_date' => '2026-06-01', 'sale_date' => '2026-06-01']);
        RegistrySale::factory()->forMember($member)->sqft('2500.00')
            ->create(['registry_date' => '2026-06-30', 'sale_date' => '2026-06-30']);

        $this->runTargets('2026-06');

        $this->assertTrue($this->verdictFor($member)->achieved);
    }

    // -----------------------------------------------------------------
    // Rule 3 — every member is measured, not only Team Leaders
    // -----------------------------------------------------------------

    #[Test]
    public function a_member_with_no_downline_achieves_the_target_on_their_own_sales(): void
    {
        $solo = Member::factory()->create();
        $this->sell($solo, '5500.00');

        $this->runTargets();

        $verdict = $this->verdictFor($solo);

        $this->assertTrue($verdict->achieved);
        $this->assertSame('150000.00', $verdict->reward_amount);
        // Own sales and team sales are the same figure for a member with nobody below.
        $this->assertSame('5500.00', $verdict->own_sqft);
        $this->assertSame('5500.00', $verdict->achieved_sqft);
    }

    #[Test]
    public function a_member_reaches_the_target_entirely_on_downline_sales(): void
    {
        $leader = Member::factory()->create();
        $deep = Member::factory()->sponsoredBy(
            Member::factory()->sponsoredBy($leader)->create()
        )->create();

        $this->sell($deep, '6000.00');

        $this->runTargets();

        $verdict = $this->verdictFor($leader);

        $this->assertTrue($verdict->achieved);
        $this->assertSame('0.00', $verdict->own_sqft);
        $this->assertSame('6000.00', $verdict->achieved_sqft);
        $this->assertSame('6000.00', $verdict->downlineSqft());
    }

    #[Test]
    public function every_leader_is_measured_independently_against_the_same_sale(): void
    {
        $top = Member::factory()->create();
        $middle = Member::factory()->sponsoredBy($top)->create();
        $bottom = Member::factory()->sponsoredBy($middle)->create();

        $this->sell($bottom, '5000.00');

        $this->runTargets();

        // The one sale achieves the target for all three, independently.
        foreach ([$top, $middle, $bottom] as $member) {
            $this->assertTrue($this->verdictFor($member)->achieved);
        }

        $this->assertSame(3, RewardLedger::where('reward_type', RewardType::Target)->count());
        $this->assertSame(
            '450000.00',
            RewardLedger::where('reward_type', RewardType::Target)->sum('amount')
        );
    }

    // -----------------------------------------------------------------
    // Rule 4 — retry on failure, pay once on achievement
    // -----------------------------------------------------------------

    #[Test]
    public function a_miss_is_recorded_without_penalty_and_the_same_target_runs_again_next_month(): void
    {
        $member = Member::factory()->create();

        $this->sell($member, '2000.00', '2026-06');
        $this->runTargets('2026-06');

        $june = $this->verdictFor($member, '2026-06');
        $this->assertFalse($june->achieved);
        $this->assertSame(1, $june->target_level, 'A failed member stays on Target 1.');

        // July: same target, unchanged threshold, and this time they reach it.
        $this->sell($member, '5000.00', '2026-07');
        $this->team->calculate('2026-07', $this->admin);
        $this->service->calculate('2026-07', $this->admin);

        $july = $this->verdictFor($member, '2026-07');
        $this->assertTrue($july->achieved);
        $this->assertSame(1, $july->target_level);
        $this->assertSame('5000.00', $july->target_sqft, 'The threshold is never raised by a failure.');
    }

    #[Test]
    public function unlimited_retries_are_allowed(): void
    {
        $member = Member::factory()->create();

        foreach (['2026-04', '2026-05', '2026-06'] as $period) {
            $this->sell($member, '1000.00', $period);
            $this->team->calculate($period, $this->admin);
            $this->service->calculate($period, $this->admin);

            $this->assertFalse($this->verdictFor($member, $period)->achieved);
        }

        $this->sell($member, '5000.00', '2026-07');
        $this->team->calculate('2026-07', $this->admin);
        $this->service->calculate('2026-07', $this->admin);

        $this->assertTrue($this->verdictFor($member, '2026-07')->achieved);
    }

    #[Test]
    public function a_member_who_achieved_the_target_is_never_measured_against_it_again(): void
    {
        $member = Member::factory()->create();

        $this->sell($member, '5000.00', '2026-06');
        $this->runTargets('2026-06');
        $this->assertTrue($this->verdictFor($member, '2026-06')->achieved);

        // A bigger month afterwards must not produce a second ₹150,000.
        $this->sell($member, '20000.00', '2026-07');
        $this->team->calculate('2026-07', $this->admin);
        $this->service->calculate('2026-07', $this->admin);

        $this->assertNull($this->verdictFor($member, '2026-07'));
        $this->assertSame(1, RewardLedger::where('member_id', $member->id)
            ->where('reward_type', RewardType::Target)->count());
        $this->assertSame('150000.00', RewardLedger::where('member_id', $member->id)
            ->where('reward_type', RewardType::Target)->sum('amount'));
    }

    #[Test]
    public function the_database_itself_refuses_a_second_achievement_of_the_same_target(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '5000.00', '2026-06');
        $this->runTargets('2026-06');

        $this->expectException(QueryException::class);

        // Bypassing the engine entirely: the unique index on
        // (member_id, achieved_level) is the hard backstop.
        TargetCalculation::insert([
            'member_id' => $member->id,
            'period' => '2026-07',
            'target_level' => 1,
            'target_sqft' => '5000.00',
            'rate' => '30.00',
            'achieved_sqft' => '5000.00',
            'own_sqft' => '5000.00',
            'achieved' => true,
            'shortfall_sqft' => '0.00',
            'reward_amount' => '150000.00',
            'achieved_level' => 1,
            'calculation_run_id' => TargetCalculation::first()->calculation_run_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function many_misses_by_the_same_member_do_not_collide_in_the_unique_index(): void
    {
        // achieved_level is NULL on a miss, and MySQL permits unlimited NULLs
        // in a unique index. Without that, a second failed month would error.
        $member = Member::factory()->create();

        foreach (['2026-05', '2026-06', '2026-07'] as $period) {
            $this->sell($member, '100.00', $period);
            $this->team->calculate($period, $this->admin);
            $this->service->calculate($period, $this->admin);
        }

        $this->assertSame(3, TargetCalculation::where('member_id', $member->id)->count());
        $this->assertSame(0, TargetCalculation::where('member_id', $member->id)
            ->whereNotNull('achieved_level')->count());
    }

    // -----------------------------------------------------------------
    // Rule 5 — status is not consulted
    // -----------------------------------------------------------------

    #[Test]
    public function member_status_has_no_effect_on_being_measured_or_paid(): void
    {
        $inactive = Member::factory()->create(['status' => MemberStatus::Inactive]);
        $this->sell($inactive, '5000.00');

        $this->runTargets();

        $verdict = $this->verdictFor($inactive);

        $this->assertTrue($verdict->achieved);
        $this->assertSame('150000.00', $verdict->reward_amount);
        $this->assertSame(1, RewardLedger::where('member_id', $inactive->id)
            ->where('reward_type', RewardType::Target)->count());
    }

    // -----------------------------------------------------------------
    // Independence and run mechanics
    // -----------------------------------------------------------------

    #[Test]
    public function the_target_engine_refuses_to_run_before_team_sales(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '5000.00');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Team Sales has not been calculated');

        $this->service->calculate('2026-06', $this->admin);
    }

    #[Test]
    public function a_second_target_run_for_the_same_period_is_refused(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '5000.00');

        $this->runTargets();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already been calculated');

        $this->service->calculate('2026-06', $this->admin);
    }

    #[Test]
    public function the_run_totals_reconcile_to_the_ledger(): void
    {
        foreach (range(1, 3) as $i) {
            $this->sell(Member::factory()->create(), '5000.00');
        }

        $this->sell(Member::factory()->create(), '1000.00');

        $this->team->calculate('2026-06', $this->admin);
        $run = $this->service->calculate('2026-06', $this->admin);

        // 4 measured, 3 achieved.
        $this->assertSame(4, $run->records_created);
        $this->assertSame('450000.00', $run->total_amount);

        // total_sqft is what was PAID ON — the threshold once per achiever —
        // so it reconciles exactly against the rate.
        $this->assertSame('15000.00', $run->total_sqft);
        $this->assertSame($run->total_amount, bcmul($run->total_sqft, '30', 2));
        $this->assertSame(
            $run->total_amount,
            RewardLedger::where('calculation_run_id', $run->id)->sum('amount')
        );
    }

    #[Test]
    public function a_missed_target_writes_no_ledger_row(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '4000.00');

        $this->runTargets();

        $this->assertSame(1, TargetCalculation::count(), 'The miss is still recorded.');
        $this->assertSame(0, RewardLedger::where('reward_type', RewardType::Target)->count());
    }

    #[Test]
    public function every_reward_traces_back_to_the_verdict_that_produced_it(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '5000.00');

        $this->runTargets();

        $entry = RewardLedger::where('reward_type', RewardType::Target)->firstOrFail();
        $verdict = $this->verdictFor($member);

        $this->assertSame('target_calculation', $entry->source_type);
        $this->assertSame($verdict->id, (int) $entry->source_id);
        $this->assertSame($verdict->calculation_run_id, $entry->calculation_run_id);
        $this->assertSame('2026-06', $entry->period);
    }

    #[Test]
    public function target_rewards_do_not_disturb_direct_or_upline_rewards(): void
    {
        $upline = Member::factory()->create();
        $seller = Member::factory()->sponsoredBy($upline)->create();

        $this->sell($seller, '5000.00');

        app(\App\Services\DirectRewardService::class)->calculate('2026-06', $this->admin);
        app(\App\Services\UplineRewardService::class)->calculate('2026-06', $this->admin);
        $this->runTargets();

        // Direct: 5,000 × 40 to the seller.
        $this->assertSame('200000.00', RewardLedger::where('member_id', $seller->id)
            ->where('reward_type', RewardType::Direct)->sum('amount'));

        // Upline: pool 5,000 × 50 = 250,000 to the single eligible upline.
        $this->assertSame('250000.00', RewardLedger::where('member_id', $upline->id)
            ->where('reward_type', RewardType::Upline)->sum('amount'));

        // Target: both reached 5,000, so both get 150,000, and neither of the
        // above amounts changed.
        $this->assertSame('300000.00', RewardLedger::where('reward_type', RewardType::Target)->sum('amount'));
    }

    #[Test]
    public function the_preview_writes_nothing(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '5000.00');
        $this->team->calculate('2026-06', $this->admin);

        $preview = $this->service->preview('2026-06');

        $this->assertSame(1, $preview['measured']);
        $this->assertSame(1, $preview['achieved']);
        $this->assertSame('150000.00', $preview['total_amount']);
        $this->assertTrue($preview['team_sales_ready']);

        $this->assertSame(0, TargetCalculation::count());
        $this->assertSame(0, RewardLedger::where('reward_type', RewardType::Target)->count());
    }

    #[Test]
    public function the_preview_reports_when_team_sales_has_not_been_run(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '5000.00');

        $preview = $this->service->preview('2026-06');

        $this->assertFalse($preview['team_sales_ready']);
        $this->assertSame(0, $preview['measured']);
    }

    #[Test]
    public function members_with_no_sales_anywhere_are_not_measured(): void
    {
        Member::factory()->count(3)->create();

        $seller = Member::factory()->create();
        $this->sell($seller, '1000.00');

        $this->runTargets();

        // Only the member whose team produced something is evaluated. A member
        // with nothing to measure gets no verdict rather than a 0-of-5,000 row.
        $this->assertSame(1, TargetCalculation::count());
        $this->assertSame($seller->id, TargetCalculation::first()->member_id);
    }

    // -----------------------------------------------------------------
    // The team tree behind a verdict
    // -----------------------------------------------------------------

    #[Test]
    public function the_contribution_tree_total_equals_the_measured_figure(): void
    {
        $leader = Member::factory()->create();
        $a = Member::factory()->sponsoredBy($leader)->create();
        $b = Member::factory()->sponsoredBy($leader)->create();
        $c = Member::factory()->sponsoredBy($a)->create();

        $this->sell($leader, '1000.00');
        $this->sell($a, '2000.00');
        $this->sell($b, '500.00');
        $this->sell($c, '1500.00');

        $this->runTargets();

        $tree = $this->service->contributionTree($leader, '2026-06');

        $this->assertSame('5000.00', $tree['total_sqft']);
        $this->assertSame($this->verdictFor($leader)->achieved_sqft, $tree['total_sqft']);

        // A's subtree carries its own 2,000 plus C's 1,500.
        $this->assertSame('1000.00', $tree['root']['own_sqft']);
        $branch = collect($tree['root']['children'])->firstWhere('id', $a->id);
        $this->assertSame('3500.00', $branch['team_sqft']);
        $this->assertSame('2000.00', $branch['own_sqft']);
    }

    #[Test]
    public function branches_that_sold_nothing_are_pruned_from_the_tree_and_counted(): void
    {
        $leader = Member::factory()->create();
        $seller = Member::factory()->sponsoredBy($leader)->create();
        $quiet = Member::factory()->sponsoredBy($leader)->create();
        Member::factory()->sponsoredBy($quiet)->create();

        $this->sell($seller, '5000.00');

        $this->runTargets();

        $tree = $this->service->contributionTree($leader, '2026-06');

        $this->assertCount(1, $tree['root']['children']);
        $this->assertSame($seller->id, $tree['root']['children'][0]['id']);
        $this->assertSame(2, $tree['pruned'], 'The silent member and their downline are omitted.');
        $this->assertSame(1, $tree['contributors']);
    }

    #[Test]
    public function a_member_who_sold_nothing_still_appears_when_their_downline_did(): void
    {
        $leader = Member::factory()->create();
        $passthrough = Member::factory()->sponsoredBy($leader)->create();
        $seller = Member::factory()->sponsoredBy($passthrough)->create();

        $this->sell($seller, '5000.00');

        $this->runTargets();

        $tree = $this->service->contributionTree($leader, '2026-06');

        $node = $tree['root']['children'][0];
        $this->assertSame($passthrough->id, $node['id']);
        $this->assertSame('0.00', $node['own_sqft'], 'They sold nothing themselves...');
        $this->assertSame('5000.00', $node['team_sqft'], '...but connect the seller below.');
        $this->assertSame($seller->id, $node['children'][0]['id']);
    }

    #[Test]
    public function the_tree_has_no_depth_limit(): void
    {
        $root = Member::factory()->create();
        $current = $root;

        foreach (range(1, 8) as $ignored) {
            $current = Member::factory()->sponsoredBy($current)->create();
        }

        $this->sell($current, '5000.00');

        $this->runTargets();

        $this->assertTrue($this->verdictFor($root)->achieved);
        $this->assertSame('5000.00', $this->service->contributionTree($root, '2026-06')['total_sqft']);
    }

    // -----------------------------------------------------------------
    // Structural guard
    // -----------------------------------------------------------------

    #[Test]
    public function the_target_engine_does_not_depend_on_the_direct_or_upline_engines(): void
    {
        // docs/02_BUSINESS_RULES.md §8 — the reward engines stay separable. The
        // Target engine reads the Team Sales MEASUREMENT, which is not a reward,
        // and must never read another engine's payouts.
        $source = file_get_contents(app_path('Services/TargetRewardService.php'));

        $this->assertStringNotContainsString('DirectRewardService', $source);
        $this->assertStringNotContainsString('UplineRewardService', $source);
        $this->assertStringNotContainsString('UplineCalculation', $source);
    }

    #[Test]
    public function the_run_is_recorded_as_a_target_run(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '5000.00');

        $this->team->calculate('2026-06', $this->admin);
        $run = $this->service->calculate('2026-06', $this->admin);

        $this->assertSame(CalculationRunType::Target, $run->run_type);
        $this->assertSame(RewardType::Target, $run->run_type->rewardType());
        $this->assertSame($this->admin->id, $run->initiated_by);
    }
}

<?php

namespace Tests\Feature\Reward;

use App\Enums\CalculationRunStatus;
use App\Enums\CalculationRunType;
use App\Enums\LedgerStatus;
use App\Enums\RewardType;
use App\Models\CalculationRun;
use App\Models\Member;
use App\Models\RegistrySale;
use App\Models\RewardLedger;
use App\Models\User;
use App\Services\DirectRewardService;
use App\Services\PeriodRecalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CalculationCenterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    private function seedSale(string $period, string $sqft, ?Member $member = null): RegistrySale
    {
        return RegistrySale::factory()
            ->forMember($member ?? Member::factory()->create())
            ->sqft($sqft)
            ->inPeriod($period)
            ->create();
    }

    #[Test]
    public function a_guest_cannot_reach_the_calculation_center(): void
    {
        $this->get(route('admin.calculations.index'))->assertRedirect(route('login'));
        $this->post(route('admin.calculations.direct'), ['period' => '2026-06'])
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function the_center_previews_a_period_without_writing_anything(): void
    {
        $this->seedSale('2026-06', '1500.00');

        $this->actingAs($this->admin)
            ->get(route('admin.calculations.index', ['period' => '2026-06']))
            ->assertOk()
            ->assertSee('60,000.00')
            ->assertSee('1,500.00');

        // A preview must not create ledger rows or runs.
        $this->assertSame(0, RewardLedger::count());
        $this->assertDatabaseCount('calculation_runs', 0);
    }

    #[Test]
    public function an_admin_can_run_the_direct_calculation(): void
    {
        $this->seedSale('2026-06', '1500.00');

        $this->actingAs($this->admin)
            ->post(route('admin.calculations.direct'), ['period' => '2026-06'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, RewardLedger::count());
        $this->assertSame('60000.00', RewardLedger::first()->amount);
    }

    #[Test]
    public function running_the_same_period_twice_is_refused_with_a_message(): void
    {
        $this->seedSale('2026-06', '1500.00');

        $this->actingAs($this->admin)->post(route('admin.calculations.direct'), ['period' => '2026-06']);

        $this->actingAs($this->admin)
            ->post(route('admin.calculations.direct'), ['period' => '2026-06'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, RewardLedger::count());
    }

    #[Test]
    public function an_invalid_period_is_rejected_by_the_form(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.calculations.direct'), ['period' => 'not-a-period'])
            ->assertSessionHasErrors('period');

        $this->assertDatabaseCount('calculation_runs', 0);
    }

    #[Test]
    public function the_run_page_lists_the_entries_it_produced(): void
    {
        $member = Member::factory()->create();
        $this->seedSale('2026-06', '1500.00', $member);

        $run = app(DirectRewardService::class)->calculate('2026-06', $this->admin);

        $this->actingAs($this->admin)
            ->get(route('admin.calculations.show', $run))
            ->assertOk()
            ->assertSee($member->member_code)
            ->assertSee('60,000.00')
            ->assertSee('Completed');
    }

    #[Test]
    public function the_direct_ledger_groups_by_member(): void
    {
        $member = Member::factory()->create();
        $this->seedSale('2026-06', '1000.00', $member);
        $this->seedSale('2026-06', '500.00', $member);

        app(DirectRewardService::class)->calculate('2026-06', $this->admin);

        $this->actingAs($this->admin)
            ->get(route('admin.rewards.direct-ledger', ['period' => '2026-06']))
            ->assertOk()
            ->assertSee($member->member_code)
            // 1,500 × 40 = 60,000 across two sales
            ->assertSee('60,000.00')
            ->assertSee('1,500.00');
    }

    #[Test]
    public function the_center_says_what_it_is_before_it_says_anything_else(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.calculations.index'))
            ->assertOk()
            // The page opened straight into a period picker and four "Calculate"
            // buttons for work that had already happened by itself. It must now
            // lead with what it is for.
            ->assertSee('Nothing here needs pressing day to day.')
            ->assertSee('all four engines are rebuilt for that')
            ->assertSee('From the sales now')
            ->assertSee('What the last run stored')
            // Every engine is named, none of them as an instruction to press.
            ->assertSee('Team Targets')
            ->assertSee('Team Sales')
            // Company Club shipped in Phase 11 and is no longer marked as
            // unbuilt. It is deliberately NOT one of the compared engine cards
            // — it has its own eligibility rule and its own preview-then-commit
            // workflow — so the Center describes it and links out to its module.
            ->assertSee('Company Club')
            ->assertSee('Open Company Club')
            ->assertSee(route('admin.company-club.overview', ['period' => now()->format('Y-m')]), false)
            ->assertDontSee('Phase 11')
            // Delivered engines must no longer be advertised as unavailable.
            // Targets 2 and 3 shipped in Phases 9-10 and must not still be listed
            // as pending, and "Calculate All" was a Phase 12 promise that Rebuild
            // now fulfils.
            ->assertDontSee('Phase 6')
            ->assertDontSee('Phase 8')
            ->assertDontSee('Phase 9')
            ->assertDontSee('Phase 10')
            ->assertDontSee('Phase 12')
            ->assertDontSee('Calculate All')
            // The claim that recalculation had to wait for Phase 12 was false
            // from the moment sale entry started rebuilding the month.
            ->assertDontSee('Recalculation is not available');
    }

    #[Test]
    public function each_engine_shows_the_live_figure_beside_the_stored_one(): void
    {
        $this->seedSale('2026-06', '1000.00');

        app(DirectRewardService::class)->calculate('2026-06', $this->admin);

        $this->actingAs($this->admin)
            ->get(route('admin.calculations.index', ['period' => '2026-06']))
            ->assertOk()
            // 1,000 × 40, worked out from the sales AND read back from the run.
            ->assertSee('40,000.00')
            ->assertSee('Matches the sales')
            ->assertSee('In step with its sales');
    }

    #[Test]
    public function a_month_that_drifted_from_its_sales_is_flagged_rather_than_left_to_be_noticed(): void
    {
        $this->seedSale('2026-06', '1000.00');

        app(DirectRewardService::class)->calculate('2026-06', $this->admin);

        // A sale arriving without a recalculation behind it — which is exactly
        // what a payment-locked month produces.
        $this->seedSale('2026-06', '500.00');

        $this->actingAs($this->admin)
            ->get(route('admin.calculations.index', ['period' => '2026-06']))
            ->assertOk()
            ->assertSee('out of step with')
            ->assertSee('Does not match the sales')
            // Sales hold 1,500; the run counted 1,000.
            ->assertSee('1,500.00')
            ->assertSee('1,000.00');
    }

    #[Test]
    public function a_month_that_produced_a_target_winner_is_not_reported_as_a_mismatch(): void
    {
        // Target achievement pays once per member EVER, so the winner is
        // graduated and never measured again: a fresh preview of this month
        // reports zero achievers while the run correctly holds ₹50,000.
        // Comparing those two would raise a false alarm on every month that
        // ever produced a winner, which is why Target is not compared at all.
        $leader = Member::factory()->create();
        $this->seedSale('2026-06', '5000.00', $leader);

        app(PeriodRecalculationService::class)->recalculate('2026-06', $this->admin);

        $this->assertSame('50000.00', RewardLedger::query()
            ->where('reward_type', RewardType::Target)
            ->sum('amount'));

        $this->actingAs($this->admin)
            ->get(route('admin.calculations.index', ['period' => '2026-06']))
            ->assertOk()
            ->assertSee('50,000.00')
            ->assertSee('Verdict recorded')
            ->assertDontSee('Does not match the sales')
            ->assertDontSee('out of step with');
    }

    #[Test]
    public function rebuilding_runs_every_engine_for_the_period(): void
    {
        $this->seedSale('2026-06', '1000.00');

        $this->actingAs($this->admin)
            ->post(route('admin.calculations.rebuild'), ['period' => '2026-06'])
            ->assertRedirect(route('admin.calculations.index', ['period' => '2026-06']))
            ->assertSessionHas('success');

        // One completed run per engine, and Team Sales before Target: the order
        // is what stops this month's targets being judged on an older rollup.
        $completed = CalculationRun::query()
            ->where('period', '2026-06')
            ->where('status', CalculationRunStatus::Completed)
            ->orderBy('id')
            ->pluck('run_type')
            ->map(fn ($type) => $type->value)
            ->all();

        $this->assertSame([
            CalculationRunType::Direct->value,
            CalculationRunType::Upline->value,
            CalculationRunType::TeamSales->value,
            CalculationRunType::Target->value,
        ], $completed);

        $this->assertSame('40000.00', RewardLedger::query()->sum('amount'));
    }

    #[Test]
    public function rebuilding_leaves_a_paid_engine_alone_and_runs_the_rest(): void
    {
        /*
         * Client-confirmed 2026-09-01. This test asserted the opposite until
         * then: one paid reward refused the entire rebuild, which meant a paid
         * Direct reward stopped Team Targets — separately approved money — from
         * ever being brought level with their sales again.
         *
         * Now the paid engine alone stands still, and the operator is told so
         * through a warning rather than having to count runs to notice.
         */
        $member = Member::factory()->create();
        $this->seedSale('2026-06', '1000.00', $member);

        app(DirectRewardService::class)->calculate('2026-06', $this->admin);

        RewardLedger::query()->where('period', '2026-06')->update([
            'status' => LedgerStatus::Paid,
            'paid_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.calculations.rebuild'), ['period' => '2026-06'])
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionHas('warning');

        // The paid Direct run is untouched: still the live one, never superseded.
        $this->assertSame(1, CalculationRun::query()
            ->where('period', '2026-06')
            ->where('run_type', CalculationRunType::Direct)
            ->where('status', CalculationRunStatus::Completed)
            ->count());

        // ...while the engines nobody was paid from did run.
        $this->assertSame(1, CalculationRun::query()
            ->where('period', '2026-06')
            ->where('run_type', CalculationRunType::Target)
            ->where('status', CalculationRunStatus::Completed)
            ->count());
    }

    #[Test]
    public function an_invalid_period_cannot_be_rebuilt(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.calculations.rebuild'), ['period' => 'not-a-period'])
            ->assertSessionHasErrors('period');

        $this->assertDatabaseCount('calculation_runs', 0);
    }

    #[Test]
    public function the_reward_reports_no_longer_live_inside_the_calculation_center(): void
    {
        $member = Member::factory()->create();

        // A report inside /admin/calculations lit up the Calculations entry in
        // the sidebar and read as "Calculations > Upline". They belong under
        // Rewards, where the URL, the breadcrumb and the menu agree.
        //
        // Upline is hidden by default since 2026-08-27, so its page is switched
        // on here: the point of this test is WHERE the report lives, and that
        // has to keep holding for the day the engine is shown again.
        config(['rewards.visibility.upline' => true]);

        $this->actingAs($this->admin)
            ->get(route('admin.rewards.upline', ['period' => '2026-06']))
            ->assertOk()
            ->assertSee('Upline Reward');

        $this->actingAs($this->admin)
            ->get(route('admin.rewards.team-sales', ['period' => '2026-06']))
            ->assertOk()
            ->assertSee('Team Sales');

        // Bookmarks and links already sent to the client still land correctly.
        foreach ([
            '/admin/calculations/upline?period=2026-06' => route('admin.rewards.upline', ['period' => '2026-06']),
            '/admin/calculations/team?period=2026-06' => route('admin.rewards.team-sales', ['period' => '2026-06']),
            '/admin/calculations/direct?period=2026-06' => route('admin.rewards.direct-ledger', ['period' => '2026-06']),
            "/admin/calculations/upline/explain/{$member->id}" => route('admin.rewards.upline.explain', $member),
        ] as $old => $new) {
            $this->actingAs($this->admin)->get($old)->assertRedirect($new);
        }
    }

    #[Test]
    public function the_menu_sends_upline_rewards_to_the_rewards_section(): void
    {
        config(['rewards.visibility.upline' => true]);

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.rewards.upline'))
            ->assertDontSee(url('/admin/calculations/upline'));
    }

    #[Test]
    public function a_hidden_engine_gets_no_card_and_no_single_engine_button(): void
    {
        // Upline is hidden (2026-08-27). Rebuild still runs it — a card exists
        // to be read against a report the operator can open, and there is none.
        $this->actingAs($this->admin)
            ->get(route('admin.calculations.index', ['period' => '2026-06']))
            ->assertOk()
            ->assertSee('Direct')
            ->assertSee('Team Sales')
            ->assertDontSee('Upline reward ledger')
            ->assertDontSee(route('admin.rewards.upline', ['period' => '2026-06']));
    }

    #[Test]
    public function rebuilding_still_runs_the_hidden_engine(): void
    {
        // The whole arrangement rests on this: hiding changed the screens, not
        // the money. If Rebuild ever stopped running Upline, uplines would
        // silently stop being paid.
        $this->seedSale('2026-06', '1000.00', Member::factory()->create());

        $this->actingAs($this->admin)
            ->post(route('admin.calculations.rebuild'), ['period' => '2026-06'])
            ->assertRedirect();

        $this->assertNotNull(
            CalculationRun::query()
                ->where('period', '2026-06')
                ->where('run_type', 'upline')
                ->where('status', CalculationRunStatus::Completed)
                ->first(),
            'Rebuild must still run the Upline engine while it is hidden.',
        );
    }

    #[Test]
    public function the_member_profile_shows_direct_rewards(): void
    {
        $member = Member::factory()->create();
        $this->seedSale('2026-06', '1500.00', $member);

        app(DirectRewardService::class)->calculate('2026-06', $this->admin);

        $this->actingAs($this->admin)
            ->get(route('admin.members.show', $member))
            ->assertOk()
            ->assertSee('Direct Reward')
            ->assertSee('2026-06')
            ->assertSee('60,000.00');
    }

    #[Test]
    public function a_member_with_no_calculated_reward_sees_an_empty_state(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.members.show', $member))
            ->assertOk()
            ->assertSee('No direct reward has been calculated');
    }
}

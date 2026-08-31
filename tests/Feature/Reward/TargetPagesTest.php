<?php

namespace Tests\Feature\Reward;

use App\Models\Member;
use App\Models\RegistrySale;
use App\Models\User;
use App\Services\TargetRewardService;
use App\Services\TeamSalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The One Month Target screens: two pages under one sidebar menu, a month
 * filter, and a team tree behind every member.
 */
class TargetPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    private function sell(Member $member, string $sqft, string $period = '2026-06'): void
    {
        RegistrySale::factory()->forMember($member)->sqft($sqft)->inPeriod($period)->create();
    }

    private function calculate(string $period = '2026-06'): void
    {
        app(TeamSalesService::class)->calculate($period, $this->admin);
        app(TargetRewardService::class)->calculate($period, $this->admin);
    }

    #[Test]
    public function the_achiever_count_reports_every_achiever_not_just_one(): void
    {
        // REGRESSION (2026-08-25): the summary tile read 1 while the list below
        // it showed two members. `SUM(achieved) as achieved` was hydrated as a
        // TargetCalculation, whose `achieved` cast is boolean — the sum came
        // back as `true`, and `(int) true` is 1. Any month with more than one
        // achiever under-reported, and "not reached" was overstated to match.
        $leader = Member::factory()->create(['name' => 'Leader']);
        $second = Member::factory()->sponsoredBy($leader)->create(['name' => 'Second']);
        $missed = Member::factory()->create(['name' => 'Missed']);

        // The downline's sale carries the leader over as well, so both achieve.
        $this->sell($second, '5200.00');
        $this->sell($missed, '900.00');

        $this->calculate();

        $response = $this->actingAs($this->admin)
            ->get(route('admin.targets.achieved', ['period' => '2026-06', 'level' => 1]))
            ->assertOk();

        $this->assertSame(2, $response->viewData('achievedCount'));
        $this->assertSame(3, $response->viewData('measured'));
        $this->assertSame(1, $response->viewData('missedCount'));
        $this->assertSame(2, $response->viewData('rows')->total());

        // The tile and the list must agree — that is the whole bug.
        $this->assertSame(
            $response->viewData('rows')->total(),
            $response->viewData('achievedCount'),
        );

        $this->assertSame('100000.00', $response->viewData('totalAmount'));
    }

    #[Test]
    public function the_level_switcher_opens_each_target_on_its_own_population(): void
    {
        $winner = Member::factory()->create(['name' => 'Cleared Target One']);
        $this->sell($winner, '5000.00', '2026-06');
        $this->sell($winner, '1500.00', '2026-07');

        $this->calculate('2026-06');
        $this->calculate('2026-07');

        // July: the member has moved to Target 2 and is inside an open window,
        // so Target 1 no longer measures them at all.
        $this->actingAs($this->admin)
            ->get(route('admin.targets.missed', ['period' => '2026-07', 'level' => 1]))
            ->assertOk()
            ->assertDontSee($winner->member_code);

        $this->actingAs($this->admin)
            ->get(route('admin.targets.missed', ['period' => '2026-07', 'level' => 2]))
            ->assertOk()
            ->assertSee($winner->member_code)
            ->assertSee('Two Month Target')
            ->assertSee('In progress')
            // The window and its running total, which is the whole point of the
            // page on a multi-month target.
            ->assertSee('2026-07 – 2026-08')
            ->assertSee('1,500.00');
    }

    #[Test]
    public function every_target_level_has_a_page_and_an_unknown_level_falls_back_to_the_first(): void
    {
        foreach ([1, 2, 3] as $level) {
            $this->actingAs($this->admin)
                ->get(route('admin.targets.achieved', ['period' => '2026-06', 'level' => $level]))
                ->assertOk();
        }

        $this->actingAs($this->admin)
            ->get(route('admin.targets.achieved', ['period' => '2026-06', 'level' => 99]))
            ->assertOk()
            ->assertSee('One Month Target');
    }

    #[Test]
    public function a_multi_month_verdict_shows_the_month_by_month_build_up(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '5000.00', '2026-06');
        $this->sell($member, '4000.00', '2026-07');
        $this->sell($member, '6000.00', '2026-08');

        $this->calculate('2026-06');
        $this->calculate('2026-07');
        $this->calculate('2026-08');

        $this->actingAs($this->admin)
            ->get(route('admin.targets.show', [$member, 'period' => '2026-08']))
            ->assertOk()
            ->assertSee('Two Month Target')
            ->assertSee('Window 2026-07 – 2026-08')
            // 4,000 then 6,000, running to the 10,000 that won it.
            ->assertSee('4,000.00')
            ->assertSee('6,000.00')
            ->assertSee('10,000.00')
            ->assertSee('200,000.00');
    }

    #[Test]
    public function guests_cannot_reach_any_target_page(): void
    {
        $member = Member::factory()->create();

        $this->get(route('admin.targets.achieved'))->assertRedirect(route('login'));
        $this->get(route('admin.targets.missed'))->assertRedirect(route('login'));
        $this->get(route('admin.targets.show', $member))->assertRedirect(route('login'));
        $this->post(route('admin.targets.run'), ['period' => '2026-06'])->assertRedirect(route('login'));
    }

    #[Test]
    public function the_achieved_page_lists_only_members_who_reached_the_target(): void
    {
        $winner = Member::factory()->create(['name' => 'Reached Target']);
        $loser = Member::factory()->create(['name' => 'Fell Short']);

        $this->sell($winner, '5000.00');
        $this->sell($loser, '1200.00');

        $this->calculate();

        $this->actingAs($this->admin)
            ->get(route('admin.targets.achieved', ['period' => '2026-06']))
            ->assertOk()
            ->assertSee($winner->member_code)
            ->assertSee('50,000.00')
            ->assertDontSee($loser->member_code);
    }

    #[Test]
    public function the_not_reached_page_lists_only_members_who_fell_short(): void
    {
        $winner = Member::factory()->create();
        $loser = Member::factory()->create();

        $this->sell($winner, '5000.00');
        $this->sell($loser, '1200.00');

        $this->calculate();

        $this->actingAs($this->admin)
            ->get(route('admin.targets.missed', ['period' => '2026-06']))
            ->assertOk()
            ->assertSee($loser->member_code)
            // Short by 5,000 - 1,200.
            ->assertSee('3,800.00')
            ->assertDontSee($winner->member_code);
    }

    #[Test]
    public function both_pages_show_the_members_own_sale_alongside_the_team_figure(): void
    {
        $leader = Member::factory()->create();
        $downline = Member::factory()->sponsoredBy($leader)->create();

        $this->sell($leader, '1500.00');
        $this->sell($downline, '4000.00');

        $this->calculate();

        $this->actingAs($this->admin)
            ->get(route('admin.targets.achieved', ['period' => '2026-06']))
            ->assertOk()
            // The team total...
            ->assertSee('5,500.00')
            // ...and the individual's own sale, shown separately.
            ->assertSee('own sale 1,500.00')
            ->assertSee('downline 4,000.00');
    }

    #[Test]
    public function the_month_filter_switches_periods_on_both_pages(): void
    {
        $member = Member::factory()->create();

        $this->sell($member, '1000.00', '2026-06');
        $this->sell($member, '2000.00', '2026-07');

        $this->calculate('2026-06');
        $this->calculate('2026-07');

        $this->actingAs($this->admin)
            ->get(route('admin.targets.missed', ['period' => '2026-06']))
            ->assertOk()
            ->assertSee('1,000.00')
            ->assertDontSee('2,000.00');

        $this->actingAs($this->admin)
            ->get(route('admin.targets.missed', ['period' => '2026-07']))
            ->assertOk()
            ->assertSee('2,000.00');
    }

    #[Test]
    public function an_invalid_period_falls_back_to_the_current_month_rather_than_erroring(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.targets.achieved', ['period' => 'not-a-month']))
            ->assertOk()
            ->assertSee(now()->format('Y-m'));
    }

    #[Test]
    public function clicking_a_member_shows_their_team_as_a_tree(): void
    {
        $leader = Member::factory()->create(['name' => 'Team Leader']);
        $a = Member::factory()->sponsoredBy($leader)->create(['name' => 'Branch A']);
        $c = Member::factory()->sponsoredBy($a)->create(['name' => 'Deep C']);

        $this->sell($leader, '1000.00');
        $this->sell($a, '2000.00');
        $this->sell($c, '2000.00');

        $this->calculate();

        $this->actingAs($this->admin)
            ->get(route('admin.targets.show', [$leader, 'period' => '2026-06']))
            ->assertOk()
            // Every level of the branch is drawn, not just direct referrals.
            ->assertSee($leader->member_code)
            ->assertSee($a->member_code)
            ->assertSee($c->member_code)
            ->assertSee('target-tree', false)
            ->assertSee('Target achieved');
    }

    #[Test]
    public function the_tree_omits_members_who_sold_nothing_and_says_how_many(): void
    {
        $leader = Member::factory()->create();
        $seller = Member::factory()->sponsoredBy($leader)->create();
        $quiet = Member::factory()->sponsoredBy($leader)->create(['name' => 'Sold Nothing']);

        $this->sell($seller, '5000.00');

        $this->calculate();

        $this->actingAs($this->admin)
            ->get(route('admin.targets.show', [$leader, 'period' => '2026-06']))
            ->assertOk()
            ->assertSee($seller->member_code)
            ->assertDontSee($quiet->member_code)
            ->assertSee('1 member omitted');
    }

    #[Test]
    public function the_detail_page_states_that_a_surplus_is_not_paid(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '7000.00');

        $this->calculate();

        $this->actingAs($this->admin)
            ->get(route('admin.targets.show', [$member, 'period' => '2026-06']))
            ->assertOk()
            ->assertSee('7,000.00')
            ->assertSee('2,000.00')
            ->assertSee('not paid and does not carry forward', false);
    }

    #[Test]
    public function the_detail_page_explains_a_miss_as_a_retry_not_a_penalty(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '900.00');

        $this->calculate();

        $this->actingAs($this->admin)
            ->get(route('admin.targets.show', [$member, 'period' => '2026-06']))
            ->assertOk()
            ->assertSee('Target not reached')
            ->assertSee('There is no penalty')
            ->assertSee('runs again next month');
    }

    #[Test]
    public function the_pages_warn_when_the_period_has_not_been_calculated(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.targets.achieved', ['period' => '2026-06']))
            ->assertOk()
            ->assertSee('Targets have not been calculated for 2026-06');
    }

    #[Test]
    public function running_the_target_from_the_center_redirects_to_the_achieved_page(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '5000.00');

        app(TeamSalesService::class)->calculate('2026-06', $this->admin);

        $this->actingAs($this->admin)
            ->post(route('admin.targets.run'), ['period' => '2026-06'])
            ->assertRedirect(route('admin.targets.achieved', ['period' => '2026-06']))
            ->assertSessionHas('success');
    }

    #[Test]
    public function running_before_team_sales_reports_the_reason_instead_of_failing_silently(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '5000.00');

        $this->actingAs($this->admin)
            ->post(route('admin.targets.run'), ['period' => '2026-06'])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    #[Test]
    public function the_sidebar_carries_one_month_target_with_both_pages_beneath_it(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.targets.achieved'))
            ->assertOk()
            ->assertSee('One Month Target')
            ->assertSee('Achieved')
            ->assertSee('Not Reached')
            ->assertSee(route('admin.targets.achieved'), false)
            ->assertSee(route('admin.targets.missed'), false);
    }

    #[Test]
    public function the_history_table_shows_every_month_the_member_was_measured(): void
    {
        $member = Member::factory()->create();

        $this->sell($member, '1000.00', '2026-05');
        $this->sell($member, '2000.00', '2026-06');

        $this->calculate('2026-05');
        $this->calculate('2026-06');

        $this->actingAs($this->admin)
            ->get(route('admin.targets.show', [$member, 'period' => '2026-06']))
            ->assertOk()
            ->assertSee('Target history')
            ->assertSee('2026-05')
            ->assertSee('2026-06');
    }
}

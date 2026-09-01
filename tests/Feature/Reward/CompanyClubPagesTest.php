<?php

namespace Tests\Feature\Reward;

use App\Enums\LedgerStatus;
use App\Enums\RewardType;
use App\Models\CompanyClubSetting;
use App\Models\Member;
use App\Models\RegistrySale;
use App\Models\RewardLedger;
use App\Models\User;
use App\Services\CompanyClubService;
use App\Services\DirectRewardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Company Club admin screens.
 */
class CompanyClubPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CompanyClubService $club;

    protected function setUp(): void
    {
        parent::setUp();

        // The payment cut-off (config rewards.payment_cutoff_days) opens payment
        // a few days into the next month. Without a frozen clock these tests
        // would pass or fail depending on which day of the month they ran.
        $this->travelTo(Carbon::parse('2026-09-20 09:00:00'));

        $this->admin = User::factory()->admin()->create();
        $this->club = app(CompanyClubService::class);
    }

    private function period(): string
    {
        return now()->subMonth()->format('Y-m');
    }

    /**
     * Club -> shiva -> seller, with one sale.
     *
     * @return array{shiva: Member, seller: Member}
     */
    private function network(string $sqft = '1000.00', ?string $period = null): array
    {
        $shiva = Member::factory()->root()->create(['name' => 'Shiva']);
        $seller = Member::factory()->sponsoredBy($shiva)->create(['name' => 'Seller One']);

        RegistrySale::factory()
            ->forMember($seller)
            ->sqft($sqft)
            ->inPeriod($period ?? $this->period())
            ->create();

        return ['shiva' => $shiva, 'seller' => $seller];
    }

    // -----------------------------------------------------------------
    // Access
    // -----------------------------------------------------------------

    #[Test]
    public function guests_are_blocked_from_every_company_club_screen(): void
    {
        $member = Member::factory()->root()->create();

        foreach ([
            route('admin.company-club.overview'),
            route('admin.company-club.tree'),
            route('admin.company-club.tree.children'),
            route('admin.company-club.calculate'),
            route('admin.company-club.preview', ['period' => $this->period()]),
            route('admin.company-club.eligible'),
            route('admin.company-club.distribution'),
            route('admin.company-club.history'),
            route('admin.company-club.explain', $member),
            route('admin.company-club.settings'),
        ] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    #[Test]
    public function guests_cannot_run_a_calculation(): void
    {
        $this->post(route('admin.company-club.run'), ['period' => $this->period()])
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('company_club_calculation_runs', 0);
    }

    // -----------------------------------------------------------------
    // Overview
    // -----------------------------------------------------------------

    #[Test]
    public function the_overview_shows_the_live_pool_for_an_uncalculated_month(): void
    {
        $this->network('1000.00');

        $this->actingAs($this->admin)
            ->get(route('admin.company-club.overview', ['period' => $this->period()]))
            ->assertOk()
            ->assertSee('Company Club')
            // 1,000 x 50 = 50,000, Indian grouping, two decimals.
            ->assertSee('50,000.00')
            ->assertSee('has not been calculated yet');
    }

    #[Test]
    public function the_overview_reports_sqft_excluded_for_an_inactive_seller(): void
    {
        $shiva = Member::factory()->root()->create();
        $inactive = Member::factory()->inactive()->sponsoredBy($shiva)->create();

        RegistrySale::factory()->forMember($inactive)->sqft('4000.00')
            ->inPeriod($this->period())->create();

        $this->actingAs($this->admin)
            ->get(route('admin.company-club.overview', ['period' => $this->period()]))
            ->assertOk()
            ->assertSee('4,000.00 Sq.Ft. excluded');
    }

    #[Test]
    public function the_overview_states_when_a_pool_has_nobody_to_receive_it(): void
    {
        // A member directly under the Club sells: pool exists, no recipients.
        $shiva = Member::factory()->root()->create();
        RegistrySale::factory()->forMember($shiva)->sqft('1000.00')
            ->inPeriod($this->period())->create();

        $this->actingAs($this->admin)
            ->get(route('admin.company-club.overview', ['period' => $this->period()]))
            ->assertOk()
            ->assertSee('with nobody to receive it');
    }

    #[Test]
    public function the_overview_shows_the_direct_club_pool_split_between_direct_members(): void
    {
        // Two roots, so the pool divides by 2. Only the second one sells.
        Member::factory()->root()->create();
        $this->network('1000.00');

        $this->actingAs($this->admin)
            ->get(route('admin.company-club.overview', ['period' => $this->period()]))
            ->assertOk()
            ->assertSee('direct Company Club members')
            // 1,000 x 30 = 30,000, split between the two direct members.
            ->assertSee('30,000.00')
            ->assertSee('15,000.00');
    }

    #[Test]
    public function the_direct_club_pool_excludes_inactive_direct_members(): void
    {
        // Two roots but one is inactive, so the pool is NOT halved.
        Member::factory()->root()->inactive()->create();
        $this->network('1000.00');

        $this->actingAs($this->admin)
            ->get(route('admin.company-club.overview', ['period' => $this->period()]))
            ->assertOk()
            ->assertSee('1 inactive member excluded')
            // The single active direct member takes the whole 30,000.
            ->assertSee('30,000.00');
    }

    // -----------------------------------------------------------------
    // Preview and calculate
    // -----------------------------------------------------------------

    #[Test]
    public function the_calculation_screen_previews_without_writing_anything(): void
    {
        $this->network('1000.00');

        $this->actingAs($this->admin)
            ->get(route('admin.company-club.calculate', ['period' => $this->period()]))
            ->assertOk()
            ->assertSee('50,000.00')
            ->assertSee('It writes nothing', false);

        $this->assertDatabaseCount('reward_ledger', 0);
        $this->assertDatabaseCount('company_club_calculation_runs', 0);
    }

    #[Test]
    public function a_month_still_running_previews_but_offers_no_calculate_button(): void
    {
        /*
         * Client-confirmed 2026-09-01. A Club share is the pool DIVIDED between
         * the eligible members, so it falls when a member joins the list as
         * well as rising when a sale lands. An amount committed on the 10th is
         * certain to move, and a member who watched their share shrink has
         * every reason to dispute it.
         *
         * Preview stays open all month, because an estimate that says it is an
         * estimate misleads nobody.
         */
        $current = now()->format('Y-m');
        $this->network('1000.00', $current);

        $this->actingAs($this->admin)
            ->get(route('admin.company-club.calculate', ['period' => $current]))
            ->assertOk()
            // The working is all there...
            ->assertSee('50,000.00')
            // ...and the commit is not.
            ->assertSee('is still running', false)
            ->assertSee('Available once '.$current.' ends', false)
            ->assertDontSee('Calculate Company Club</button>', false);

        $this->assertDatabaseCount('reward_ledger', 0);
    }

    #[Test]
    public function a_month_still_running_refuses_the_calculation_even_if_the_post_is_forced(): void
    {
        // The disabled button is a courtesy; this is the rule.
        $current = now()->format('Y-m');
        $this->network('1000.00', $current);

        $this->actingAs($this->admin)
            ->post(route('admin.company-club.run'), ['period' => $current])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseCount('company_club_calculation_runs', 0);
        $this->assertDatabaseCount('reward_ledger', 0);
    }

    #[Test]
    public function a_month_that_has_ended_offers_the_calculate_button(): void
    {
        $this->network('1000.00');

        $this->actingAs($this->admin)
            ->get(route('admin.company-club.calculate', ['period' => $this->period()]))
            ->assertOk()
            ->assertSee('Calculate Company Club', false)
            ->assertDontSee('is still running', false);
    }

    #[Test]
    public function the_ajax_preview_writes_nothing_and_returns_the_standard_envelope(): void
    {
        $this->network('1000.00');

        $response = $this->actingAs($this->admin)
            ->getJson(route('admin.company-club.preview', ['period' => $this->period()]))
            ->assertOk()
            ->assertJsonStructure([
                'success', 'message', 'errors',
                'data' => ['period', 'total_sqft', 'pool_amount', 'eligible_count', 'equal_share', 'recipients'],
            ]);

        $this->assertSame('50000.00', $response->json('data.pool_amount'));
        $this->assertDatabaseCount('reward_ledger', 0);
        $this->assertDatabaseCount('company_club_calculation_runs', 0);
    }

    #[Test]
    public function an_invalid_period_is_rejected_by_the_preview_endpoint(): void
    {
        $this->actingAs($this->admin)
            ->getJson(route('admin.company-club.preview', ['period' => 'nonsense']))
            ->assertStatus(422);
    }

    #[Test]
    public function calculating_writes_the_ledger_and_redirects_to_the_distribution(): void
    {
        ['shiva' => $shiva] = $this->network('1000.00');

        $this->actingAs($this->admin)
            ->post(route('admin.company-club.run'), ['period' => $this->period()])
            ->assertRedirect(route('admin.company-club.distribution', ['period' => $this->period()]))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('reward_ledger', [
            'member_id' => $shiva->id,
            'reward_type' => RewardType::CompanyClub->value,
            'amount' => '50000.00',
        ]);
    }

    #[Test]
    public function a_duplicate_calculation_is_refused_with_a_message(): void
    {
        $this->network('1000.00');

        $this->actingAs($this->admin)
            ->post(route('admin.company-club.run'), ['period' => $this->period()]);

        $this->actingAs($this->admin)
            ->post(route('admin.company-club.run'), ['period' => $this->period()])
            ->assertSessionHas('error');

        $this->assertSame(1, RewardLedger::query()->ofType(RewardType::CompanyClub)->count());
    }

    #[Test]
    public function a_malformed_period_is_rejected_by_the_run_form(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.company-club.run'), ['period' => '2026-13'])
            ->assertSessionHasErrors('period');
    }

    // -----------------------------------------------------------------
    // The calculation date is always visible
    // -----------------------------------------------------------------

    #[Test]
    public function every_figure_screen_states_when_it_was_last_calculated(): void
    {
        $this->network('1000.00');
        $run = $this->club->calculate($this->period(), $this->admin);

        foreach ([
            route('admin.company-club.overview', ['period' => $this->period()]),
            route('admin.company-club.eligible', ['period' => $this->period()]),
            route('admin.company-club.distribution', ['period' => $this->period()]),
        ] as $url) {
            $this->actingAs($this->admin)->get($url)
                ->assertOk()
                ->assertSee('Last calculated')
                ->assertSee($run->run_code)
                ->assertSee($this->admin->name);
        }
    }

    #[Test]
    public function a_month_out_of_step_says_so_and_offers_a_rebuild(): void
    {
        ['seller' => $seller] = $this->network('1000.00');
        $this->club->calculate($this->period(), $this->admin);

        RegistrySale::factory()->forMember($seller)->sqft('500.00')
            ->inPeriod($this->period())->create();

        $this->actingAs($this->admin)
            ->get(route('admin.company-club.distribution', ['period' => $this->period()]))
            ->assertOk()
            ->assertSee('These figures are out of date')
            ->assertSee('Recalculate');
    }

    #[Test]
    public function a_previous_run_is_shown_beside_the_current_one(): void
    {
        ['seller' => $seller] = $this->network('1000.00');
        $first = $this->club->calculate($this->period(), $this->admin);

        RegistrySale::factory()->forMember($seller)->sqft('1000.00')
            ->inPeriod($this->period())->create();
        $second = $this->club->recalculate($this->period(), $this->admin);

        $this->actingAs($this->admin)
            ->get(route('admin.company-club.distribution', ['period' => $this->period()]))
            ->assertOk()
            ->assertSee($second->run_code)
            ->assertSee('Previously')
            ->assertSee($first->run_code);
    }

    // -----------------------------------------------------------------
    // Distribution and explanation
    // -----------------------------------------------------------------

    #[Test]
    public function the_distribution_screen_draws_the_calculation_tree(): void
    {
        $this->network('1000.00');
        $this->club->calculate($this->period(), $this->admin);

        $this->actingAs($this->admin)
            ->get(route('admin.company-club.distribution', ['period' => $this->period()]))
            ->assertOk()
            ->assertSee('COMPANY CLUB')
            ->assertSee('1,000.00 Sq.Ft.')
            ->assertSee('50,000.00')
            ->assertSee('Shiva');
    }

    #[Test]
    public function the_explanation_shows_the_formula_and_every_qualifying_path(): void
    {
        // Shiva qualifies through two separate selling branches.
        $shiva = Member::factory()->root()->create(['name' => 'Shiva']);
        $s1 = Member::factory()->sponsoredBy($shiva)->create();
        $s2 = Member::factory()->sponsoredBy($s1)->create();
        $s3 = Member::factory()->sponsoredBy($shiva)->create();
        $s4 = Member::factory()->sponsoredBy($s3)->create();

        foreach ([$s2, $s4] as $seller) {
            RegistrySale::factory()->forMember($seller)->sqft('1000.00')
                ->inPeriod($this->period())->create();
        }

        $this->club->calculate($this->period(), $this->admin);

        $this->actingAs($this->admin)
            ->get(route('admin.company-club.explain', [$shiva, 'period' => $this->period()]))
            ->assertOk()
            ->assertSee('Formula')
            ->assertSee('2 paths')
            ->assertSee('paid <strong>once</strong>', false)
            ->assertSee($s2->member_code)
            ->assertSee($s4->member_code);
    }

    #[Test]
    public function the_explanation_names_a_skipped_inactive_sponsor(): void
    {
        $shiva = Member::factory()->root()->create();
        $inactive = Member::factory()->inactive()->sponsoredBy($shiva)->create();
        $seller = Member::factory()->sponsoredBy($inactive)->create();

        RegistrySale::factory()->forMember($seller)->sqft('1000.00')
            ->inPeriod($this->period())->create();

        $this->club->calculate($this->period(), $this->admin);

        $this->actingAs($this->admin)
            ->get(route('admin.company-club.explain', [$shiva, 'period' => $this->period()]))
            ->assertOk()
            ->assertSee('inactive, skipped')
            ->assertSee($inactive->member_code);
    }

    #[Test]
    public function a_member_who_received_nothing_is_told_why(): void
    {
        $this->network('1000.00');
        $this->club->calculate($this->period(), $this->admin);

        $outsider = Member::factory()->root()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.company-club.explain', [$outsider, 'period' => $this->period()]))
            ->assertOk()
            ->assertSee('received no Company Club reward');
    }

    // -----------------------------------------------------------------
    // History
    // -----------------------------------------------------------------

    #[Test]
    public function the_history_lists_superseded_runs_alongside_the_live_one(): void
    {
        ['seller' => $seller] = $this->network('1000.00');
        $first = $this->club->calculate($this->period(), $this->admin);

        RegistrySale::factory()->forMember($seller)->sqft('1000.00')
            ->inPeriod($this->period())->create();
        $second = $this->club->recalculate($this->period(), $this->admin);

        $this->actingAs($this->admin)
            ->get(route('admin.company-club.history'))
            ->assertOk()
            ->assertSee($first->run_code)
            ->assertSee($second->run_code)
            ->assertSee('Superseded')
            ->assertSee('50,000.00')
            ->assertSee('1,00,000.00');
    }

    #[Test]
    public function a_superseded_run_page_explains_that_its_detail_was_cleared(): void
    {
        ['seller' => $seller] = $this->network('1000.00');
        $first = $this->club->calculate($this->period(), $this->admin);
        $this->club->recalculate($this->period(), $this->admin);

        $this->actingAs($this->admin)
            ->get(route('admin.company-club.runs.show', $first))
            ->assertOk()
            ->assertSee('has been superseded')
            ->assertSee('was cleared when')
            // The permanent totals survive.
            ->assertSee('50,000.00');
    }

    // -----------------------------------------------------------------
    // Payment
    // -----------------------------------------------------------------

    #[Test]
    public function a_reward_can_be_marked_paid_once_the_month_is_over(): void
    {
        ['shiva' => $shiva] = $this->network('1000.00');
        $this->club->calculate($this->period(), $this->admin);

        $reward = RewardLedger::query()->ofType(RewardType::CompanyClub)->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.company-club.paid', $reward))
            ->assertSessionHas('success');

        $this->assertSame(LedgerStatus::Paid, $reward->refresh()->status);
    }

    #[Test]
    public function a_non_company_club_reward_cannot_be_paid_through_this_screen(): void
    {
        $this->network('1000.00');
        app(DirectRewardService::class)->calculate($this->period(), $this->admin);

        $directReward = RewardLedger::query()->ofType(RewardType::Direct)->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.company-club.paid', $directReward))
            ->assertSessionHas('error');

        $this->assertSame(LedgerStatus::Posted, $directReward->refresh()->status);
    }

    // -----------------------------------------------------------------
    // Network tree
    // -----------------------------------------------------------------

    #[Test]
    public function the_tree_page_renders_no_member_rows_at_all(): void
    {
        $shiva = Member::factory()->root()->create(['name' => 'Shiva Gupta']);
        Member::factory()->sponsoredBy($shiva)->create(['name' => 'Downline Person']);

        $this->actingAs($this->admin)
            ->get(route('admin.company-club.tree'))
            ->assertOk()
            // Nodes arrive over AJAX; nothing is shipped in the HTML.
            ->assertDontSee('Shiva Gupta')
            ->assertDontSee('Downline Person')
            ->assertDontSee($shiva->member_code);
    }

    #[Test]
    public function the_tree_endpoint_returns_the_club_members_then_one_level(): void
    {
        $shiva = Member::factory()->root()->create();
        $child = Member::factory()->sponsoredBy($shiva)->create();
        Member::factory()->sponsoredBy($child)->create();

        $roots = $this->actingAs($this->admin)
            ->getJson(route('admin.company-club.tree.children'))
            ->assertOk()
            ->json('data.nodes');

        $this->assertCount(1, $roots);
        $this->assertSame($shiva->member_code, $roots[0]['member_code']);
        $this->assertSame(1, $roots[0]['children']);

        $level = $this->actingAs($this->admin)
            ->getJson(route('admin.company-club.tree.children', ['member_id' => $shiva->id]))
            ->assertOk()
            ->json('data.nodes');

        // Exactly one level, never the grandchild.
        $this->assertCount(1, $level);
        $this->assertSame($child->member_code, $level[0]['member_code']);
    }

    // -----------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------

    #[Test]
    public function the_settings_screen_saves_and_renames_the_module(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.company-club.settings.update'), [
                'display_name' => 'Corporate Club',
                'reward_rate' => '60.00',
                'max_upline_levels' => 4,
            ])
            ->assertRedirect(route('admin.company-club.settings'))
            ->assertSessionHas('success');

        $settings = CompanyClubSetting::current();
        $this->assertSame('Corporate Club', $settings->name());
        $this->assertSame('60.00', $settings->rate());
        $this->assertSame(4, $settings->maxLevels());

        // The rename reaches the navigation and the screens.
        $this->actingAs($this->admin)
            ->get(route('admin.company-club.overview'))
            ->assertOk()
            ->assertSee('Corporate Club');
    }

    #[Test]
    public function a_zero_rate_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.company-club.settings.update'), [
                'display_name' => 'Company Club',
                'reward_rate' => '0',
                'max_upline_levels' => 5,
            ])
            ->assertSessionHasErrors('reward_rate');
    }

    #[Test]
    public function a_zero_level_cap_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.company-club.settings.update'), [
                'display_name' => 'Company Club',
                'reward_rate' => '50',
                'max_upline_levels' => 0,
            ])
            ->assertSessionHasErrors('max_upline_levels');
    }

    #[Test]
    public function the_sidebar_no_longer_advertises_company_club_as_unbuilt(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Company Club')
            ->assertSee(route('admin.company-club.overview'), false)
            ->assertDontSee('title="Delivered in Phase 11"', false);
    }
}

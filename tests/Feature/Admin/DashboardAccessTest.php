<?php

namespace Tests\Feature\Admin;

use App\Enums\UserStatus;
use App\Models\Member;
use App\Models\RegistrySale;
use App\Models\User;
use App\Services\DirectRewardService;
use App\Services\UplineRewardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_guest_is_redirected_to_the_login_screen(): void
    {
        $this->get(route('admin.dashboard'))
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function an_admin_can_view_the_dashboard(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Dashboard');
    }

    #[Test]
    public function a_manager_can_view_the_dashboard(): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->get(route('admin.dashboard'))
            ->assertOk();
    }

    #[Test]
    public function a_user_deactivated_mid_session_is_logged_out(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk();

        $user->update(['status' => UserStatus::Inactive]);

        $this->get(route('admin.dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    #[Test]
    public function the_root_url_redirects_to_the_dashboard(): void
    {
        $this->get('/')->assertRedirect('/admin/dashboard');
    }

    #[Test]
    public function the_dashboard_names_the_visible_reward_engines(): void
    {
        // Upline was named here too until it was hidden on 2026-08-27. The
        // engine still runs and still pays; the dashboard no longer reports a
        // figure for a reward with no screen behind it.
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.dashboard'))
            ->assertSee('Direct Reward')
            ->assertSee('Target Reward')
            ->assertDontSee('Upline Reward')
            // Each engine states its basis, so a rate is never an unexplained number.
            ->assertSee('Own approved Sq.Ft. × ₹'.config('rewards.rates.direct'), false);
    }

    #[Test]
    public function the_headline_total_never_counts_a_hidden_engine(): void
    {
        // The "All rewards" figure is summed over the cards on show. If it kept
        // counting Upline the dashboard would report money that nothing below
        // it accounts for, which is worse than either showing or hiding it.
        $member = Member::factory()->create();
        $sponsor = Member::factory()->create();
        $member->update(['sponsor_id' => $sponsor->id]);

        $period = now()->subMonth()->format('Y-m');
        RegistrySale::factory()->forMember($member)->sqft('1000.00')
            ->create(['registry_date' => $period.'-15', 'sale_date' => $period.'-15']);

        $admin = User::factory()->admin()->create();
        app(DirectRewardService::class)->calculate($period, $admin);
        app(UplineRewardService::class)->calculate($period, $admin);

        // Direct ₹40,000 is shown; the ₹50,000 upline pool is written but not counted.
        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('₹40,000.00')
            ->assertSee('direct sale + team target, all months');
    }

    #[Test]
    public function the_dashboard_carries_real_figures_rather_than_placeholders(): void
    {
        $member = Member::factory()->create();
        RegistrySale::factory()->forMember($member)->sqft('1500.00')
            ->create(['registry_date' => now()->toDateString(), 'sale_date' => now()->toDateString()]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.dashboard'))
            ->assertOk()
            // The sale is counted...
            ->assertSee('1,500.00')
            // ...and the member is counted.
            ->assertSee($member->member_code)
            // The old placeholder wording is gone for good.
            ->assertDontSee('Available in Phase');
    }

    #[Test]
    public function delivered_features_no_longer_advertise_a_build_phase(): void
    {
        // Build order is scaffolding the operator has no use for. A DELIVERED
        // feature is simply there; only a screen that does not exist yet still
        // says when it arrives, so the menu is never a dead end.
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Available in Phase')
            // Phases 2-8 are all delivered and must carry no marker anywhere.
            ->assertDontSee('Phase 2')
            ->assertDontSee('Phase 3')
            ->assertDontSee('Phase 4')
            ->assertDontSee('Phase 5')
            ->assertDontSee('Phase 6')
            ->assertDontSee('Phase 7')
            ->assertDontSee('Phase 8')
            ->assertDontSee('P5</span>', false)
            ->assertDontSee('P8</span>', false);
    }

    #[Test]
    public function screens_that_do_not_exist_yet_still_say_when_they_arrive(): void
    {
        // The counterpart to the test above: hiding the phase on a delivered
        // feature must not turn an unbuilt menu item into a dead end.
        //
        // This has pointed at Company Club (Phase 11) and then Reward Ledger
        // (Phase 13) as each was delivered. Reports is now the nearest unbuilt
        // screen and takes over the job of proving the rule still holds.
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Reports')
            ->assertSee('Delivered in Phase 14', false);
    }

    #[Test]
    public function the_sales_trend_chart_offers_a_table_view(): void
    {
        // Identity and value are never carried by the bars alone.
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Sales trend')
            ->assertSee('Show as table');
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Enums\UserStatus;
use App\Models\User;
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
    public function the_dashboard_shows_the_four_confirmed_reward_rates(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.dashboard'))
            ->assertSee('Direct Sale')
            ->assertSee('Upline')
            ->assertSee('Team Target')
            ->assertSee('Company Club')
            ->assertSee('&#8377;40', false)
            ->assertSee('&#8377;50', false)
            ->assertSee('&#8377;30', false);
    }

    #[Test]
    public function no_reward_figures_are_fabricated_on_the_dashboard(): void
    {
        // KPI tiles must stay blank until their engines exist (Phases 2-11).
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.dashboard'))
            ->assertSee('Available in Phase 5')
            ->assertSee('Available in Phase 11');
    }
}

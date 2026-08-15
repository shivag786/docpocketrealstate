<?php

namespace Tests\Feature\Reward;

use App\Models\Member;
use App\Models\RegistrySale;
use App\Models\RewardLedger;
use App\Models\User;
use App\Services\DirectRewardService;
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
            ->get(route('admin.calculations.direct.ledger', ['period' => '2026-06']))
            ->assertOk()
            ->assertSee($member->member_code)
            // 1,500 × 40 = 60,000 across two sales
            ->assertSee('60,000.00')
            ->assertSee('1,500.00');
    }

    #[Test]
    public function the_center_offers_the_wired_engines_and_marks_the_rest(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.calculations.index'))
            ->assertOk()
            // Wired as of Phase 6.
            ->assertSee('Calculate Direct')
            ->assertSee('Calculate Upline')
            // Still to come, labelled with their delivering phase.
            ->assertSee('Calculate Team Targets')
            ->assertSee('Calculate Company Club')
            ->assertSee('Calculate All')
            ->assertSee('Phase 8')
            ->assertSee('Phase 11')
            ->assertSee('Phase 12')
            // Upline must no longer be advertised as unavailable.
            ->assertDontSee('Phase 6');
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

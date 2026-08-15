<?php

namespace Tests\Feature\Reward;

use App\Enums\CalculationRunType;
use App\Models\Member;
use App\Models\RegistrySale;
use App\Models\RewardLedger;
use App\Models\TeamCalculation;
use App\Models\User;
use App\Services\TeamSalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * docs/05_CALCULATION_ENGINE_SPEC.md §C and docs/01_MASTER_DEVELOPMENT_PLAN.md
 * Phase 7 — "Rahul/A/B/C sample totals reconcile".
 */
class TeamSalesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private TeamSalesService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->service = app(TeamSalesService::class);
    }

    /**
     * The sample network from the development plan.
     *
     *   Rahul
     *   ├── A
     *   │   └── C
     *   └── B
     *
     * @return array<string, Member>
     */
    private function sampleNetwork(): array
    {
        $rahul = Member::factory()->create(['name' => 'Rahul']);
        $a = Member::factory()->sponsoredBy($rahul)->create(['name' => 'A']);
        $b = Member::factory()->sponsoredBy($rahul)->create(['name' => 'B']);
        $c = Member::factory()->sponsoredBy($a)->create(['name' => 'C']);

        return compact('rahul', 'a', 'b', 'c');
    }

    private function sell(Member $member, string $sqft, string $period = '2026-06'): void
    {
        RegistrySale::factory()->forMember($member)->sqft($sqft)->inPeriod($period)->create();
    }

    private function totalFor(Member $leader, string $period = '2026-06'): ?TeamCalculation
    {
        return TeamCalculation::where('leader_id', $leader->id)->where('period', $period)->first();
    }

    #[Test]
    public function the_sample_network_totals_reconcile(): void
    {
        ['rahul' => $rahul, 'a' => $a, 'b' => $b, 'c' => $c] = $this->sampleNetwork();

        $this->sell($rahul, '1000.00');
        $this->sell($a, '2000.00');
        $this->sell($b, '500.00');
        $this->sell($c, '1500.00');

        $this->service->calculate('2026-06', $this->admin);

        // Rahul: own 1000 + A 2000 + B 500 + C 1500 = 5000
        $rahulTotal = $this->totalFor($rahul);
        $this->assertSame('1000.00', $rahulTotal->own_sqft);
        $this->assertSame('2500.00', $rahulTotal->direct_team_sqft); // A + B only
        $this->assertSame('5000.00', $rahulTotal->total_team_sqft);
        $this->assertSame(3, $rahulTotal->contributing_members);

        // A: own 2000 + C 1500 = 3500
        $aTotal = $this->totalFor($a);
        $this->assertSame('2000.00', $aTotal->own_sqft);
        $this->assertSame('1500.00', $aTotal->direct_team_sqft);
        $this->assertSame('3500.00', $aTotal->total_team_sqft);

        // B: own 500 only, no downline
        $bTotal = $this->totalFor($b);
        $this->assertSame('500.00', $bTotal->own_sqft);
        $this->assertSame('500.00', $bTotal->total_team_sqft);
        $this->assertSame(0, $bTotal->contributing_members);
        $this->assertTrue($bTotal->isSoloContributor());

        // C: own 1500 only, leaf
        $this->assertSame('1500.00', $this->totalFor($c)->total_team_sqft);
    }

    #[Test]
    public function every_leader_is_calculated_independently(): void
    {
        ['rahul' => $rahul, 'a' => $a, 'c' => $c] = $this->sampleNetwork();

        $this->sell($c, '1500.00');

        $this->service->calculate('2026-06', $this->admin);

        // C's single sale counts in C's own total, in A's team, and in Rahul's.
        // That overlap is intentional — three independent measurements.
        $this->assertSame('1500.00', $this->totalFor($c)->total_team_sqft);
        $this->assertSame('1500.00', $this->totalFor($a)->total_team_sqft);
        $this->assertSame('1500.00', $this->totalFor($rahul)->total_team_sqft);

        // But only C owns it.
        $this->assertSame('1500.00', $this->totalFor($c)->own_sqft);
        $this->assertSame('0.00', $this->totalFor($a)->own_sqft);
        $this->assertSame('0.00', $this->totalFor($rahul)->own_sqft);
    }

    #[Test]
    public function team_sales_have_no_depth_limit(): void
    {
        // The 5-level cap belongs to the upline reward, not to team sales.
        $previous = null;
        $chain = [];

        foreach (range(1, 9) as $i) {
            $previous = $previous === null
                ? Member::factory()->create()
                : Member::factory()->sponsoredBy($previous)->create();
            $chain[] = $previous;
        }

        $root = $chain[0];
        $deepest = end($chain);

        $this->sell($deepest, '800.00');

        $this->service->calculate('2026-06', $this->admin);

        // The sale is 8 links below the root and still counts in full.
        $this->assertSame('800.00', $this->totalFor($root)->total_team_sqft);
        $this->assertSame(1, $this->totalFor($root)->contributing_members);
    }

    #[Test]
    public function the_company_total_counts_each_sale_once(): void
    {
        ['rahul' => $rahul, 'a' => $a, 'c' => $c] = $this->sampleNetwork();

        $this->sell($rahul, '1000.00');
        $this->sell($a, '2000.00');
        $this->sell($c, '1500.00');

        $run = $this->service->calculate('2026-06', $this->admin);

        // Summing total_team_sqft would give 4500 + 3500 + 1500 = 9500, which
        // multiplies sales by chain height. The run records the real figure.
        $this->assertSame('4500.00', $run->total_sqft);
        $this->assertSame(
            '9500.00',
            number_format((float) TeamCalculation::sum('total_team_sqft'), 2, '.', '')
        );
    }

    #[Test]
    public function this_engine_pays_nobody(): void
    {
        ['rahul' => $rahul] = $this->sampleNetwork();
        $this->sell($rahul, '5000.00');

        $run = $this->service->calculate('2026-06', $this->admin);

        $this->assertSame('0.00', $run->total_amount);
        $this->assertSame(0, RewardLedger::count());
    }

    #[Test]
    public function the_target_columns_are_left_for_the_target_engine(): void
    {
        ['rahul' => $rahul] = $this->sampleNetwork();
        $this->sell($rahul, '9999.00');

        $this->service->calculate('2026-06', $this->admin);

        $row = $this->totalFor($rahul);

        $this->assertNull($row->target_sqft);
        $this->assertNull($row->achieved);
        $this->assertNull($row->reward_amount);
    }

    #[Test]
    public function members_with_no_sales_anywhere_get_no_row(): void
    {
        ['rahul' => $rahul, 'b' => $b] = $this->sampleNetwork();

        $this->sell($rahul, '1000.00');

        $this->service->calculate('2026-06', $this->admin);

        $this->assertNotNull($this->totalFor($rahul));
        $this->assertNull($this->totalFor($b));
    }

    #[Test]
    public function sales_outside_the_period_are_excluded(): void
    {
        ['rahul' => $rahul, 'a' => $a] = $this->sampleNetwork();

        $this->sell($a, '1000.00', '2026-06');
        $this->sell($a, '9000.00', '2026-05');

        $this->service->calculate('2026-06', $this->admin);

        $this->assertSame('1000.00', $this->totalFor($rahul)->total_team_sqft);
    }

    #[Test]
    public function each_period_is_rolled_up_separately(): void
    {
        ['rahul' => $rahul, 'a' => $a] = $this->sampleNetwork();

        $this->sell($a, '1000.00', '2026-05');
        $this->sell($a, '2000.00', '2026-06');

        $this->service->calculate('2026-05', $this->admin);
        $this->service->calculate('2026-06', $this->admin);

        $this->assertSame('1000.00', $this->totalFor($rahul, '2026-05')->total_team_sqft);
        $this->assertSame('2000.00', $this->totalFor($rahul, '2026-06')->total_team_sqft);
    }

    #[Test]
    public function an_inactive_members_sales_still_count_toward_their_leaders_team(): void
    {
        // A sale that happened, happened. Member status governs who RECEIVES an
        // upline share, not whether a completed sale is measured.
        // FLAGGED: not explicitly confirmed by the client — see PROJECT_STATE.
        ['rahul' => $rahul, 'a' => $a] = $this->sampleNetwork();

        $a->update(['status' => \App\Enums\MemberStatus::Inactive]);
        $this->sell($a, '1500.00');

        $this->service->calculate('2026-06', $this->admin);

        $this->assertSame('1500.00', $this->totalFor($rahul)->total_team_sqft);
    }

    #[Test]
    public function a_second_run_for_the_same_period_is_refused(): void
    {
        ['rahul' => $rahul] = $this->sampleNetwork();
        $this->sell($rahul, '1000.00');

        $this->service->calculate('2026-06', $this->admin);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already been calculated');

        $this->service->calculate('2026-06', $this->admin);
    }

    #[Test]
    public function contributors_name_everyone_who_rolled_up(): void
    {
        ['rahul' => $rahul, 'a' => $a, 'b' => $b, 'c' => $c] = $this->sampleNetwork();

        $this->sell($rahul, '1000.00');
        $this->sell($a, '2000.00');
        $this->sell($c, '1500.00');

        $contributors = $this->service->contributors($rahul, '2026-06');

        $byId = $contributors->keyBy('member_id');

        $this->assertSame(0, (int) $byId[$rahul->id]->depth);
        $this->assertSame(1, (int) $byId[$a->id]->depth);
        $this->assertSame(2, (int) $byId[$c->id]->depth);

        // B sold nothing, so does not appear.
        $this->assertArrayNotHasKey($b->id, $byId->all());

        $this->assertSame('4500.00', number_format((float) $contributors->sum('sqft'), 2, '.', ''));
    }

    #[Test]
    public function the_preview_writes_nothing(): void
    {
        ['rahul' => $rahul] = $this->sampleNetwork();
        $this->sell($rahul, '1000.00');

        $preview = $this->service->preview('2026-06');

        $this->assertSame(1, $preview['leaders']);
        $this->assertSame('1000.00', $preview['company_sqft']);
        $this->assertSame(0, TeamCalculation::count());
        $this->assertDatabaseCount('calculation_runs', 0);
    }

    #[Test]
    public function the_engine_does_not_depend_on_the_reward_engines(): void
    {
        // Team sales is a measurement. It must not reach into Direct or Upline.
        $source = file_get_contents(app_path('Services/TeamSalesService.php'));

        foreach (['DirectRewardService', 'UplineRewardService', 'RewardLedger'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                str_replace(['reward_ledger rows', 'Target engine'], '', $source),
                "TeamSalesService must not depend on [{$forbidden}]."
            );
        }
    }

    #[Test]
    public function a_guest_cannot_run_or_view_team_sales(): void
    {
        $this->post(route('admin.calculations.team'), ['period' => '2026-06'])
            ->assertRedirect(route('login'));

        $this->get(route('admin.calculations.team.report'))->assertRedirect(route('login'));
    }

    #[Test]
    public function the_report_shows_each_leaders_totals(): void
    {
        ['rahul' => $rahul, 'a' => $a] = $this->sampleNetwork();

        $this->sell($rahul, '1000.00');
        $this->sell($a, '2000.00');

        $this->service->calculate('2026-06', $this->admin);

        $this->actingAs($this->admin)
            ->get(route('admin.calculations.team.report', ['period' => '2026-06']))
            ->assertOk()
            ->assertSee($rahul->member_code)
            ->assertSee('3,000.00')   // Rahul's total team
            ->assertSee('2,000.00');  // A's own
    }

    #[Test]
    public function the_contributors_page_explains_a_leaders_total(): void
    {
        ['rahul' => $rahul, 'c' => $c] = $this->sampleNetwork();

        $this->sell($c, '1500.00');
        $this->service->calculate('2026-06', $this->admin);

        $this->actingAs($this->admin)
            ->get(route('admin.calculations.team.contributors', [$rahul, 'period' => '2026-06']))
            ->assertOk()
            ->assertSee($c->member_code)
            ->assertSee('1,500.00')
            ->assertSee('+2');   // C sits two levels below Rahul
    }
}

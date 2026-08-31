<?php

namespace Tests\Feature\Reward;

use App\Models\Member;
use App\Models\RegistrySale;
use App\Models\User;
use App\Services\UplineRewardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Upline Explorer.
 *
 * THE SCREEN IS HIDDEN, NOT DELETED (client-confirmed 2026-08-27). The engine
 * still runs and still pays ₹50 per Sq.Ft.; only the pages are switched off,
 * and one config line brings them back. These tests therefore switch the flag
 * on and go on covering the page in full, so what is being restored is known to
 * work — plus one test at the bottom that the page really is unreachable while
 * the flag is off.
 */
class UplineExplorerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private UplineRewardService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rewards.visibility.upline' => true]);

        $this->admin = User::factory()->admin()->create();
        $this->service = app(UplineRewardService::class);
    }

    #[Test]
    public function the_explorer_is_unreachable_while_the_engine_is_hidden(): void
    {
        config(['rewards.visibility.upline' => false]);

        $member = Member::factory()->create();

        // 404 rather than 403: to an operator these pages do not exist. Nothing
        // links to them any more, so reaching one means an old bookmark.
        $this->actingAs($this->admin)
            ->get(route('admin.rewards.upline.explain', $member))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->get(route('admin.rewards.upline'))
            ->assertNotFound();
    }

    #[Test]
    public function the_sidebar_drops_the_upline_entry_while_it_is_hidden(): void
    {
        config(['rewards.visibility.upline' => false]);

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Upline Rewards')
            ->assertDontSee(url('/admin/rewards/upline'));
    }

    /**
     * Root A → B → C(inactive) → D → E → F → G(seller): the shape used in the
     * demo seeder, exercising both compression and the 5-upline limit.
     *
     * @return array<string, Member>
     */
    private function demoBranch(): array
    {
        $a = Member::factory()->create(['name' => 'A Root']);
        $b = Member::factory()->sponsoredBy($a)->create(['name' => 'B']);
        $c = Member::factory()->sponsoredBy($b)->inactive()->create(['name' => 'C inactive']);
        $d = Member::factory()->sponsoredBy($c)->create(['name' => 'D']);
        $e = Member::factory()->sponsoredBy($d)->create(['name' => 'E']);
        $f = Member::factory()->sponsoredBy($e)->create(['name' => 'F']);
        $g = Member::factory()->sponsoredBy($f)->create(['name' => 'G seller']);

        return compact('a', 'b', 'c', 'd', 'e', 'f', 'g');
    }

    #[Test]
    public function the_annotated_chain_explains_every_ancestor(): void
    {
        $branch = $this->demoBranch();

        $chain = $this->service->annotatedChain($branch['g']);

        // Six ancestors above G.
        $this->assertCount(6, $chain);

        // F, E, D are eligible at levels 1-3.
        $this->assertSame($branch['f']->id, $chain[0]['member']->id);
        $this->assertTrue($chain[0]['eligible']);
        $this->assertSame(1, $chain[0]['level']);

        // C is inactive: skipped, and it does not consume a level.
        $this->assertSame($branch['c']->id, $chain[3]['member']->id);
        $this->assertFalse($chain[3]['eligible']);
        $this->assertNull($chain[3]['level']);
        $this->assertStringContainsString('Inactive', $chain[3]['reason']);

        // B then takes level 4 despite sitting 5 links up.
        $this->assertSame($branch['b']->id, $chain[4]['member']->id);
        $this->assertTrue($chain[4]['eligible']);
        $this->assertSame(4, $chain[4]['level']);
        $this->assertSame(5, $chain[4]['depth']);

        // A takes the fifth and final slot.
        $this->assertSame(5, $chain[5]['level']);
    }

    #[Test]
    public function the_chain_marks_ancestors_beyond_the_five_upline_limit(): void
    {
        // Seven active ancestors: the seventh must fall outside the limit.
        $previous = null;
        foreach (range(1, 7) as $i) {
            $previous = $previous === null
                ? Member::factory()->create()
                : Member::factory()->sponsoredBy($previous)->create();
        }
        $seller = Member::factory()->sponsoredBy($previous)->create();

        $chain = $this->service->annotatedChain($seller);

        $this->assertCount(7, $chain);
        $this->assertTrue($chain[4]['eligible']);
        $this->assertFalse($chain[5]['eligible']);
        $this->assertStringContainsString('Beyond the 5-upline limit', $chain[5]['reason']);
        $this->assertStringContainsString('Beyond the 5-upline limit', $chain[6]['reason']);
    }

    #[Test]
    public function the_path_from_root_is_ordered_top_down(): void
    {
        $branch = $this->demoBranch();

        $path = $this->service->pathFromRoot($branch['g']);

        $this->assertSame($branch['a']->id, $path->first()->id);
        $this->assertSame($branch['f']->id, $path->last()->id);
        $this->assertCount(6, $path);
    }

    #[Test]
    public function a_sellers_distribution_is_reported_with_its_pool(): void
    {
        $branch = $this->demoBranch();

        RegistrySale::factory()->forMember($branch['g'])->sqft('1500.00')->inPeriod('2026-06')->create();
        $this->service->calculate('2026-06', $this->admin);

        $distribution = $this->service->distributionBySeller($branch['g'], '2026-06');

        $this->assertSame('1500.00', $distribution['sqft']);
        $this->assertSame('75000.00', $distribution['pool']);
        $this->assertSame(5, $distribution['count']);
        $this->assertSame('15000.00', $distribution['share']);
    }

    #[Test]
    public function receipts_show_which_sellers_paid_a_member(): void
    {
        $branch = $this->demoBranch();

        RegistrySale::factory()->forMember($branch['g'])->sqft('1500.00')->inPeriod('2026-06')->create();
        RegistrySale::factory()->forMember($branch['f'])->sqft('800.00')->inPeriod('2026-06')->create();

        $this->service->calculate('2026-06', $this->admin);

        // The root receives from both sellers.
        $receipts = $this->service->receiptsFor($branch['a'], '2026-06');

        $this->assertCount(2, $receipts);
        $this->assertEqualsCanonicalizing(
            [$branch['g']->id, $branch['f']->id],
            $receipts->pluck('seller_id')->all()
        );
    }

    #[Test]
    public function a_guest_cannot_open_the_explorer(): void
    {
        $member = Member::factory()->create();

        $this->get(route('admin.rewards.upline.explain', $member))
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function the_explorer_renders_the_hierarchy_and_the_reasons(): void
    {
        $branch = $this->demoBranch();

        RegistrySale::factory()->forMember($branch['g'])->sqft('1500.00')->inPeriod('2026-06')->create();
        $this->service->calculate('2026-06', $this->admin);

        $this->actingAs($this->admin)
            ->get(route('admin.rewards.upline.explain', [$branch['g'], 'period' => '2026-06']))
            ->assertOk()
            ->assertSee('How an upline reward is produced')
            ->assertSee($branch['a']->member_code)   // root in the hierarchy
            ->assertSee($branch['g']->member_code)   // the member itself
            ->assertSee('75,000.00')                 // pool
            ->assertSee('15,000.00')                 // each share
            ->assertSee('Inactive — skipped, the walk continues upward')
            ->assertSee('Eligible — receives an equal share');
    }

    #[Test]
    public function the_explorer_tells_a_member_under_the_club_they_generate_no_upline(): void
    {
        // A member with no sponsor sits directly under the Company Club. The UI
        // used to call this a "root member"; the wording was replaced on
        // 2026-08-19 because the Club is what is actually above them.
        $root = Member::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.rewards.upline.explain', $root))
            ->assertOk()
            ->assertSee('sits directly under Company Club')
            ->assertDontSee('This is a root member');
    }

    #[Test]
    public function the_pool_comes_from_the_seller_not_the_upline(): void
    {
        // Guards the rule against the most likely misreading: the pool is the
        // SELLER's Sq.Ft. × ₹50, never the receiving upline's own sales.
        $upline = Member::factory()->create();
        $seller = Member::factory()->sponsoredBy($upline)->create();

        // The upline sells a lot; the seller sells a little.
        RegistrySale::factory()->forMember($upline)->sqft('9000.00')->inPeriod('2026-06')->create();
        RegistrySale::factory()->forMember($seller)->sqft('1000.00')->inPeriod('2026-06')->create();

        $this->service->calculate('2026-06', $this->admin);

        $receipts = $this->service->receiptsFor($upline, '2026-06');

        // The upline receives from the seller's 1,000 Sq.Ft., not their own 9,000.
        $this->assertCount(1, $receipts);
        $this->assertSame('1000.00', $receipts->first()->seller_sqft);
        $this->assertSame('50000.00', $receipts->first()->pool_amount);
        $this->assertSame('50000.00', $receipts->first()->receiver_amount);

        // The upline's own 9,000 Sq.Ft. paid THEIR uplines — they have none, so
        // it produced nothing.
        $this->assertSame(0, $this->service->distributionBySeller($upline, '2026-06')['count']);
    }
}

<?php

namespace Tests\Feature\Reward;

use App\Models\Member;
use App\Models\RegistrySale;
use App\Models\User;
use App\Services\CompanyClubReportService;
use App\Services\CompanyClubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Income Distribution screen.
 *
 * Two trees on one page: each seller with the sponsors their sale paid, and the
 * whole network from the Club down. It must be readable without jargon, honest
 * about collapsed branches, and cheap enough to open on a large network.
 */
class CompanyClubIncomeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CompanyClubService $club;

    private CompanyClubReportService $reports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->club = app(CompanyClubService::class);
        $this->reports = app(CompanyClubReportService::class);
    }

    private function period(): string
    {
        return now()->subMonth()->format('Y-m');
    }

    private function sell(Member $member, string $sqft): RegistrySale
    {
        return RegistrySale::factory()
            ->forMember($member)
            ->sqft($sqft)
            ->inPeriod($this->period())
            ->create();
    }

    /**
     * A chain beneath the Club.
     *
     * @param  array<int, bool>  $activeFlags
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
    // Access
    // -----------------------------------------------------------------

    #[Test]
    public function guests_are_blocked(): void
    {
        $this->get(route('admin.company-club.income'))->assertRedirect(route('login'));
        $this->get(route('admin.company-club.income.branch'))->assertRedirect(route('login'));
    }

    // -----------------------------------------------------------------
    // The page
    // -----------------------------------------------------------------

    #[Test]
    public function the_page_shows_each_seller_with_their_total_sales(): void
    {
        [$shiva, $seller] = $this->chain([true, true]);
        $seller->update(['name' => 'Selling Member']);

        // Two sales that must be summed into one figure for the month.
        $this->sell($seller, '1200.50');
        $this->sell($seller, '800.00');

        $this->club->calculate($this->period(), $this->admin);

        $this->actingAs($this->admin)
            ->get(route('admin.company-club.income', ['period' => $this->period()]))
            ->assertOk()
            ->assertSee('Selling Member')
            // 1,200.50 + 800.00 summed, not shown as two rows.
            ->assertSee('2,000.50 Sq.Ft.');
    }

    #[Test]
    public function the_page_shows_the_sponsors_a_sale_paid_and_their_amounts(): void
    {
        [$top, $middle, $seller] = $this->chain([true, true, true]);
        $this->sell($seller, '1000.00');

        $this->club->calculate($this->period(), $this->admin);

        // Pool 50,000 over 2 recipients = 25,000 each.
        $this->actingAs($this->admin)
            ->get(route('admin.company-club.income', ['period' => $this->period()]))
            ->assertOk()
            ->assertSee($top->member_code)
            ->assertSee($middle->member_code)
            ->assertSee('25,000.00');
    }

    #[Test]
    public function no_level_numbering_appears_anywhere_on_the_page(): void
    {
        // The client asked for the tree without "L1 / L2" jargon.
        $members = $this->chain([true, true, true, true, true, true]);
        $this->sell(end($members), '1000.00');

        $this->club->calculate($this->period(), $this->admin);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.company-club.income', ['period' => $this->period()]))
            ->assertOk()
            ->getContent();

        /*
         * Scan the page's own content and nothing else.
         *
         * Cutting at the first "Income Distribution" used to be the way, and it
         * cut in the <title> — leaving the whole layout in the haystack. That
         * made the test fail whenever the random CSRF token happened to contain
         * "L1".."L5", which it does roughly one run in twenty. <main> is the
         * boundary the layout actually draws.
         */
        $body = substr(
            $html,
            (int) strpos($html, '<main class="app-main">'),
            (int) strrpos($html, '</main>') - (int) strpos($html, '<main class="app-main">'),
        );

        // Every form in the content carries one too.
        $body = preg_replace('/name="_token" value="[^"]*"/', '', $body);

        foreach (['L1', 'L2', 'L3', 'L4', 'L5', 'Level 1', 'Level 2', 'upline level'] as $jargon) {
            $this->assertStringNotContainsString(
                $jargon,
                $body,
                "The income page must stay free of level jargon, found [{$jargon}].",
            );
        }
    }

    #[Test]
    public function a_skipped_inactive_sponsor_is_shown_rather_than_silently_missing(): void
    {
        // A chain that jumped over somebody with no explanation would look
        // broken, which is the opposite of simple.
        [$top, $inactive, $seller] = $this->chain([true, false, true]);
        $this->sell($seller, '1000.00');

        $this->club->calculate($this->period(), $this->admin);

        $this->actingAs($this->admin)
            ->get(route('admin.company-club.income', ['period' => $this->period()]))
            ->assertOk()
            ->assertSee($inactive->member_code)
            ->assertSee('inactive &mdash; skipped', false);
    }

    #[Test]
    public function an_inactive_sellers_sales_are_listed_but_marked_as_not_counted(): void
    {
        $top = Member::factory()->root()->create();
        $inactive = Member::factory()->inactive()->sponsoredBy($top)->create();

        $this->sell($inactive, '5000.00');

        $this->actingAs($this->admin)
            ->get(route('admin.company-club.income', ['period' => $this->period()]))
            ->assertOk()
            ->assertSee('5,000.00 Sq.Ft.')
            ->assertSee('Inactive seller');
    }

    #[Test]
    public function a_seller_directly_under_the_club_is_shown_with_nobody_above_them(): void
    {
        $shiva = Member::factory()->root()->create();
        $this->sell($shiva, '1000.00');

        $this->actingAs($this->admin)
            ->get(route('admin.company-club.income', ['period' => $this->period()]))
            ->assertOk()
            ->assertSee('nobody above to pay');
    }

    #[Test]
    public function the_network_tree_draws_the_club_as_the_root(): void
    {
        $shiva = Member::factory()->root()->create(['name' => 'Shiva']);
        Member::factory()->sponsoredBy($shiva)->create(['name' => 'Downline One']);

        $this->actingAs($this->admin)
            ->get(route('admin.company-club.income', ['period' => $this->period()]))
            ->assertOk()
            ->assertSee('Company Club')
            ->assertSee('Shiva')
            ->assertSee('Downline One');
    }

    #[Test]
    public function the_month_filter_switches_periods(): void
    {
        [$shiva, $seller] = $this->chain([true, true]);

        $earlier = now()->subMonths(2)->format('Y-m');

        RegistrySale::factory()->forMember($seller)->sqft('333.00')
            ->inPeriod($earlier)->create();
        $this->sell($seller, '777.00');

        $this->actingAs($this->admin)
            ->get(route('admin.company-club.income', ['period' => $earlier]))
            ->assertOk()
            ->assertSee('333.00')
            ->assertDontSee('777.00');
    }

    #[Test]
    public function an_uncalculated_month_still_renders_with_sales_but_no_amounts(): void
    {
        [$shiva, $seller] = $this->chain([true, true]);
        $this->sell($seller, '1000.00');

        $this->actingAs($this->admin)
            ->get(route('admin.company-club.income', ['period' => $this->period()]))
            ->assertOk()
            ->assertSee('1,000.00 Sq.Ft.')
            ->assertSee('has not been calculated');
    }

    // -----------------------------------------------------------------
    // Depth limiting and load-more
    // -----------------------------------------------------------------

    #[Test]
    public function only_the_first_levels_are_drawn_and_the_rest_are_collapsed(): void
    {
        // Six deep: the tree renders three levels and stops.
        $members = $this->chain(array_fill(0, 6, true));

        $tree = $this->reports->incomeTree($this->period());

        $node = $tree['roots'][0];
        $depth = 1;

        while ($node['children'] !== []) {
            $node = $node['children'][0];
            $depth++;
        }

        $this->assertSame(CompanyClubReportService::TREE_DEPTH + 1, $depth);
        $this->assertTrue($node['collapsed'], 'The deepest drawn node should offer a load-more.');
        $this->assertSame(1, $node['child_count']);
    }

    #[Test]
    public function a_collapsed_branch_still_reports_its_full_total(): void
    {
        // THE IMPORTANT ONE: the picture may be partial, the figures never are.
        $members = $this->chain(array_fill(0, 6, true));

        // Only the deepest member sells, well below the drawn depth.
        $this->sell(end($members), '4321.00');

        $tree = $this->reports->incomeTree($this->period());

        $this->assertSame('4321.00', $tree['roots'][0]['branch_sqft']);
        $this->assertSame('0.00', $tree['roots'][0]['own_sqft']);
        $this->assertSame('4321.00', $tree['totals']['sqft']);
    }

    #[Test]
    public function the_load_more_endpoint_returns_the_next_branch(): void
    {
        $members = $this->chain(array_fill(0, 6, true));
        $this->sell(end($members), '1000.00');

        $deepest = $members[3];

        $response = $this->actingAs($this->admin)
            ->getJson(route('admin.company-club.income.branch', [
                'period' => $this->period(),
                'member_id' => $deepest->id,
            ]))
            ->assertOk()
            ->assertJsonStructure(['success', 'message', 'errors', 'data' => ['html']]);

        $html = $response->json('data.html');

        $this->assertStringContainsString($members[4]->member_code, $html);
    }

    #[Test]
    public function the_load_more_endpoint_rejects_an_unknown_member(): void
    {
        $this->actingAs($this->admin)
            ->getJson(route('admin.company-club.income.branch', [
                'period' => $this->period(),
                'member_id' => 999999,
            ]))
            ->assertStatus(422);
    }

    #[Test]
    public function the_page_does_not_query_once_per_member(): void
    {
        /*
         * A tree screen that walks the database per node is the classic way one
         * dies on real data. This pins the cost as flat: 30 members must not
         * cost meaningfully more queries than 5.
         */
        $root = Member::factory()->root()->create();
        $previous = $root;

        for ($i = 0; $i < 29; $i++) {
            $previous = Member::factory()->sponsoredBy($previous)->create();
            $this->sell($previous, '100.00');
        }

        $this->club->calculate($this->period(), $this->admin);

        DB::enableQueryLog();
        $this->reports->incomeTree($this->period());
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            10,
            $queries,
            "The income tree took {$queries} queries for 30 members — it is walking per node.",
        );
    }

    #[Test]
    public function branches_are_ordered_with_the_largest_first(): void
    {
        $small = Member::factory()->root()->create(['name' => 'Small Branch']);
        $big = Member::factory()->root()->create(['name' => 'Big Branch']);

        $this->sell(Member::factory()->sponsoredBy($small)->create(), '100.00');
        $this->sell(Member::factory()->sponsoredBy($big)->create(), '9000.00');

        $tree = $this->reports->incomeTree($this->period());

        $this->assertSame($big->id, $tree['roots'][0]['id']);
        $this->assertSame($small->id, $tree['roots'][1]['id']);
    }

    #[Test]
    public function the_totals_reconcile_with_the_run(): void
    {
        [$top, $middle, $seller] = $this->chain([true, true, true]);
        $this->sell($seller, '1000.00');

        $run = $this->club->calculate($this->period(), $this->admin);
        $tree = $this->reports->incomeTree($this->period());

        $this->assertSame((string) $run->total_sqft, $tree['totals']['sqft']);
        $this->assertSame((string) $run->distributed_amount, $tree['totals']['reward']);
        $this->assertSame((int) $run->eligible_count, $tree['totals']['recipients']);
    }
}

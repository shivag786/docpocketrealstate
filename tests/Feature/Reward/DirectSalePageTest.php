<?php

namespace Tests\Feature\Reward;

use App\Http\Controllers\Admin\DirectSaleController;
use App\Models\Member;
use App\Models\RegistrySale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Direct Sale report.
 *
 * Opens on today, filters by member and date, and works the reward out on every
 * row as Sq.Ft. × ₹40.
 */
class DirectSalePageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    private function sellOn(Member $member, string $sqft, string $date): RegistrySale
    {
        return RegistrySale::factory()->forMember($member)->sqft($sqft)->create([
            'registry_date' => $date,
            'sale_date' => $date,
        ]);
    }

    #[Test]
    public function guests_are_redirected_to_login(): void
    {
        $this->get(route('admin.rewards.direct-sales'))->assertRedirect(route('login'));
    }

    #[Test]
    public function the_page_opens_on_todays_sales(): void
    {
        $member = Member::factory()->create();

        $this->sellOn($member, '1000.00', now()->toDateString());
        $this->sellOn($member, '9999.00', now()->subMonth()->toDateString());

        $this->actingAs($this->admin)
            ->get(route('admin.rewards.direct-sales'))
            ->assertOk()
            // Today's sale and its reward: 1,000 x 40.
            ->assertSee('1,000.00')
            ->assertSee('40,000.00')
            // Last month's sale is not in view.
            ->assertDontSee('9,999.00');
    }

    #[Test]
    public function every_row_shows_the_sqft_multiplied_by_the_rate(): void
    {
        $member = Member::factory()->create();
        $this->sellOn($member, '1234.56', now()->toDateString());

        $this->actingAs($this->admin)
            ->get(route('admin.rewards.direct-sales'))
            ->assertOk()
            ->assertSee('1,234.56')
            // 1,234.56 x 40 = 49,382.40 exactly.
            ->assertSee('49,382.40');
    }

    #[Test]
    public function the_total_covers_the_whole_filtered_set_not_just_the_page(): void
    {
        $member = Member::factory()->create();

        foreach (range(1, 30) as $ignored) {
            $this->sellOn($member, '100.00', now()->toDateString());
        }

        // 30 sales at 100 Sq.Ft. = 3,000 Sq.Ft. = ₹120,000, while page one of a
        // 25-row page holds only 25 of them.
        $this->actingAs($this->admin)
            ->get(route('admin.rewards.direct-sales', ['per_page' => 25]))
            ->assertOk()
            ->assertSee('3,000.00')
            ->assertSee('120,000.00');
    }

    #[Test]
    public function the_member_filter_defaults_to_everyone_and_narrows_on_request(): void
    {
        $a = Member::factory()->create();
        $b = Member::factory()->create();

        $this->sellOn($a, '1100.00', now()->toDateString());
        $this->sellOn($b, '2200.00', now()->toDateString());

        // Default: both.
        $this->actingAs($this->admin)
            ->get(route('admin.rewards.direct-sales'))
            ->assertOk()
            ->assertSee('1,100.00')
            ->assertSee('2,200.00')
            ->assertSee('All members');

        // Filtered: only A.
        $this->actingAs($this->admin)
            ->get(route('admin.rewards.direct-sales', ['member_id' => $a->id]))
            ->assertOk()
            ->assertSee('1,100.00')
            ->assertDontSee('2,200.00');
    }

    #[Test]
    public function a_date_range_can_be_given_explicitly(): void
    {
        $member = Member::factory()->create();

        $this->sellOn($member, '500.00', '2026-06-05');
        $this->sellOn($member, '700.00', '2026-06-20');
        $this->sellOn($member, '900.00', '2026-07-05');

        $this->actingAs($this->admin)
            ->get(route('admin.rewards.direct-sales', ['from' => '2026-06-01', 'to' => '2026-06-30']))
            ->assertOk()
            ->assertSee('500.00')
            ->assertSee('700.00')
            ->assertDontSee('900.00')
            // 1,200 x 40
            ->assertSee('48,000.00');
    }

    #[Test]
    public function the_quick_ranges_widen_the_view(): void
    {
        $member = Member::factory()->create();
        $this->sellOn($member, '640.00', now()->startOfMonth()->toDateString());

        // Today only — the sale is at the start of the month, so unless today IS
        // the 1st it is out of view. Either way the month range must show it.
        $this->actingAs($this->admin)
            ->get(route('admin.rewards.direct-sales', ['range' => 'month']))
            ->assertOk()
            ->assertSee('640.00')
            ->assertSee('25,600.00');

        $this->actingAs($this->admin)
            ->get(route('admin.rewards.direct-sales', ['range' => 'all']))
            ->assertOk()
            ->assertSee('640.00');
    }

    #[Test]
    public function all_the_documented_page_sizes_are_offered_and_accepted(): void
    {
        $this->assertSame([25, 50, 150, 250, 500, 1000], DirectSaleController::PAGE_SIZES);

        $member = Member::factory()->create();
        $this->sellOn($member, '100.00', now()->toDateString());

        $response = $this->actingAs($this->admin)->get(route('admin.rewards.direct-sales'));

        foreach (DirectSaleController::PAGE_SIZES as $size) {
            $response->assertSee('value="'.$size.'"', false);
        }

        // A size outside the list falls back rather than being honoured.
        $this->actingAs($this->admin)
            ->get(route('admin.rewards.direct-sales', ['per_page' => 7]))
            ->assertOk()
            ->assertSee('value="25" selected', false);
    }

    #[Test]
    public function the_table_paginates(): void
    {
        $member = Member::factory()->create();

        foreach (range(1, 60) as $ignored) {
            $this->sellOn($member, '10.00', now()->toDateString());
        }

        $this->actingAs($this->admin)
            ->get(route('admin.rewards.direct-sales', ['per_page' => 25]))
            ->assertOk()
            ->assertSee('Page 1 of 3')
            ->assertSee('25 per page');
    }

    #[Test]
    public function paging_keeps_the_filters(): void
    {
        $member = Member::factory()->create();

        foreach (range(1, 40) as $ignored) {
            $this->sellOn($member, '10.00', now()->toDateString());
        }

        $this->actingAs($this->admin)
            ->get(route('admin.rewards.direct-sales', ['per_page' => 25, 'member_id' => $member->id, 'page' => 2]))
            ->assertOk()
            // The member filter survives into the pagination links.
            ->assertSee('member_id='.$member->id, false);
    }

    #[Test]
    public function the_columns_can_be_sorted(): void
    {
        $member = Member::factory()->create();
        $this->sellOn($member, '100.00', now()->toDateString());
        $this->sellOn($member, '900.00', now()->toDateString());

        $ascending = $this->actingAs($this->admin)
            ->get(route('admin.rewards.direct-sales', ['sort' => 'sqft', 'direction' => 'asc']))
            ->assertOk()
            ->getContent();

        $this->assertLessThan(
            strpos($ascending, '900.00'),
            strpos($ascending, '100.00'),
            'Ascending by Sq.Ft. must put the smaller sale first.'
        );

        $descending = $this->actingAs($this->admin)
            ->get(route('admin.rewards.direct-sales', ['sort' => 'sqft', 'direction' => 'desc']))
            ->assertOk()
            ->getContent();

        $this->assertLessThan(
            strpos($descending, '100.00'),
            strpos($descending, '900.00'),
            'Descending by Sq.Ft. must put the larger sale first.'
        );
    }

    #[Test]
    public function an_unknown_sort_column_is_ignored_rather_than_trusted(): void
    {
        $member = Member::factory()->create();
        $this->sellOn($member, '100.00', now()->toDateString());

        $this->actingAs($this->admin)
            ->get(route('admin.rewards.direct-sales', ['sort' => 'members.password', 'direction' => 'drop']))
            ->assertOk()
            ->assertSee('100.00');
    }

    #[Test]
    public function a_malformed_date_does_not_break_the_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.rewards.direct-sales', ['from' => 'yesterday', 'to' => ';DROP']))
            ->assertOk();
    }

    #[Test]
    public function an_empty_day_explains_how_to_widen_the_view(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.rewards.direct-sales'))
            ->assertOk()
            ->assertSee('No approved sales')
            ->assertSee('Show this month');
    }

    #[Test]
    public function the_page_is_reachable_from_the_sidebar(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.rewards.direct-sales'), false)
            ->assertSee('Direct Sale');
    }
}

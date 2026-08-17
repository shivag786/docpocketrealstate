<?php

namespace Tests\Feature\Sale;

use App\Http\Controllers\Admin\RegistrySaleController;
use App\Models\Member;
use App\Models\Project;
use App\Models\Property;
use App\Models\RegistrySale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SalesHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    #[Test]
    public function a_guest_cannot_view_sales_history(): void
    {
        $this->get(route('admin.sales.index'))->assertRedirect(route('login'));
    }

    #[Test]
    public function sales_are_listed(): void
    {
        $sale = RegistrySale::factory()->create([
            'registry_reference' => 'REG-FINDME',
            'registry_date' => now()->toDateString(),
            'sale_date' => now()->toDateString(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.sales.index'))
            ->assertOk()
            ->assertSee('REG-FINDME')
            ->assertSee($sale->member->member_code);
    }

    #[Test]
    public function the_page_opens_on_todays_sales(): void
    {
        // Client-confirmed: the history opens on the current day, like the
        // Direct Sale report.
        RegistrySale::factory()->create([
            'registry_reference' => 'REG-TODAY',
            'registry_date' => now()->toDateString(),
            'sale_date' => now()->toDateString(),
        ]);

        RegistrySale::factory()->create([
            'registry_reference' => 'REG-OLD',
            'registry_date' => now()->subMonth()->toDateString(),
            'sale_date' => now()->subMonth()->toDateString(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.sales.index'))
            ->assertOk()
            ->assertSee('REG-TODAY')
            ->assertDontSee('REG-OLD');
    }

    #[Test]
    public function the_quick_ranges_widen_past_today(): void
    {
        RegistrySale::factory()->create([
            'registry_reference' => 'REG-OLD',
            'registry_date' => now()->subMonths(2)->toDateString(),
            'sale_date' => now()->subMonths(2)->toDateString(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.sales.index', ['range' => 'all']))
            ->assertOk()
            ->assertSee('REG-OLD');
    }

    #[Test]
    public function a_search_looks_across_all_dates_not_just_today(): void
    {
        // The today-only default must never make search look broken: a request
        // that names something specific is asking to find it wherever it is.
        RegistrySale::factory()->create([
            'registry_reference' => 'REG-LONGAGO',
            'registry_date' => now()->subMonths(3)->toDateString(),
            'sale_date' => now()->subMonths(3)->toDateString(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.sales.index', ['q' => 'REG-LONGAGO']))
            ->assertOk()
            ->assertSee('REG-LONGAGO');
    }

    #[Test]
    public function filtering_by_member_looks_across_all_dates(): void
    {
        $member = Member::factory()->create();

        RegistrySale::factory()->forMember($member)->create([
            'registry_reference' => 'REG-THEIRS',
            'registry_date' => now()->subMonths(4)->toDateString(),
            'sale_date' => now()->subMonths(4)->toDateString(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.sales.index', ['member_id' => $member->id]))
            ->assertOk()
            ->assertSee('REG-THEIRS');
    }

    #[Test]
    public function each_row_shows_the_direct_reward_the_sale_earned(): void
    {
        RegistrySale::factory()->sqft('1250.50')->create([
            'registry_date' => now()->toDateString(),
            'sale_date' => now()->toDateString(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.sales.index'))
            ->assertOk()
            ->assertSee('1,250.50')
            // 1,250.50 × 40 = 50,020.00
            ->assertSee('50,020.00');
    }

    #[Test]
    public function all_the_documented_page_sizes_are_offered(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.sales.index'));

        foreach (RegistrySaleController::PAGE_SIZES as $size) {
            $response->assertSee('value="'.$size.'"', false);
        }

        // An unlisted size is not honoured.
        $this->actingAs($this->admin)
            ->get(route('admin.sales.index', ['per_page' => 3]))
            ->assertOk()
            ->assertSee('value="25" selected', false);
    }

    #[Test]
    public function the_columns_can_be_sorted(): void
    {
        RegistrySale::factory()->sqft('100.00')->create([
            'registry_date' => now()->toDateString(), 'sale_date' => now()->toDateString(),
        ]);
        RegistrySale::factory()->sqft('900.00')->create([
            'registry_date' => now()->toDateString(), 'sale_date' => now()->toDateString(),
        ]);

        $ascending = $this->actingAs($this->admin)
            ->get(route('admin.sales.index', ['sort' => 'sqft', 'direction' => 'asc']))
            ->assertOk()
            ->getContent();

        $this->assertLessThan(
            strpos($ascending, '900.00'),
            strpos($ascending, '100.00'),
            'Ascending by Sq.Ft. must put the smaller sale first.'
        );
    }

    #[Test]
    public function an_unknown_sort_column_is_ignored_rather_than_trusted(): void
    {
        RegistrySale::factory()->create([
            'registry_reference' => 'REG-SAFE',
            'registry_date' => now()->toDateString(), 'sale_date' => now()->toDateString(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.sales.index', ['sort' => 'members.password', 'direction' => 'drop']))
            ->assertOk()
            ->assertSee('REG-SAFE');
    }

    #[Test]
    public function history_can_be_searched_by_registry_number(): void
    {
        RegistrySale::factory()->create(['registry_reference' => 'REG-AAA']);
        RegistrySale::factory()->create(['registry_reference' => 'REG-BBB']);

        $this->actingAs($this->admin)
            ->get(route('admin.sales.index', ['q' => 'REG-AAA']))
            ->assertOk()
            ->assertSee('REG-AAA')
            ->assertDontSee('REG-BBB');
    }

    #[Test]
    public function history_can_be_searched_by_member(): void
    {
        $member = Member::factory()->create(['name' => 'Searchable Seller']);
        RegistrySale::factory()->forMember($member)->create(['registry_reference' => 'REG-MINE']);
        RegistrySale::factory()->create(['registry_reference' => 'REG-THEIRS']);

        $this->actingAs($this->admin)
            ->get(route('admin.sales.index', ['q' => 'Searchable Seller']))
            ->assertOk()
            ->assertSee('REG-MINE')
            ->assertDontSee('REG-THEIRS');
    }

    #[Test]
    public function history_can_be_filtered_by_date_range(): void
    {
        RegistrySale::factory()->inPeriod('2026-01')->create(['registry_reference' => 'REG-JAN']);
        RegistrySale::factory()->inPeriod('2026-06')->create(['registry_reference' => 'REG-JUN']);

        $this->actingAs($this->admin)
            ->get(route('admin.sales.index', ['from' => '2026-06-01', 'to' => '2026-06-30']))
            ->assertOk()
            ->assertSee('REG-JUN')
            ->assertDontSee('REG-JAN');
    }

    #[Test]
    public function history_can_be_filtered_by_project(): void
    {
        $projectA = Project::factory()->create();
        $propertyA = Property::factory()->forProject($projectA)->create();
        RegistrySale::factory()->forProperty($propertyA)->create(['registry_reference' => 'REG-A']);

        RegistrySale::factory()->create(['registry_reference' => 'REG-B']);

        $this->actingAs($this->admin)
            ->get(route('admin.sales.index', ['project_id' => $projectA->id]))
            ->assertOk()
            ->assertSee('REG-A')
            ->assertDontSee('REG-B');
    }

    #[Test]
    public function totals_reflect_the_active_filters_not_the_page(): void
    {
        config(['members.per_page' => 2]);

        $member = Member::factory()->create();

        foreach (['1000.00', '2000.00', '3000.50'] as $i => $sqft) {
            RegistrySale::factory()->forMember($member)->sqft($sqft)->create([
                'registry_reference' => "REG-T{$i}",
            ]);
        }

        RegistrySale::factory()->sqft('9999.00')->create(['registry_reference' => 'REG-OTHER']);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.sales.index', ['member_id' => $member->id]))
            ->assertOk();

        // 3 sales totalling 6,000.50 — across 2 pages, but totals cover all of them.
        $response->assertSee('6,000.50');
        $response->assertSee('>3<', false);
    }

    #[Test]
    public function the_period_filter_uses_the_registry_date(): void
    {
        RegistrySale::factory()->create([
            'registry_reference' => 'REG-MARCH',
            'registry_date' => '2026-03-15',
            'sale_date' => '2026-01-02',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.sales.index', ['period' => '2026-03']))
            ->assertOk()
            ->assertSee('REG-MARCH');

        $this->actingAs($this->admin)
            ->get(route('admin.sales.index', ['period' => '2026-01']))
            ->assertOk()
            ->assertDontSee('REG-MARCH');
    }

    #[Test]
    public function a_sale_detail_page_shows_its_reward_period(): void
    {
        $sale = RegistrySale::factory()->create([
            'registry_reference' => 'REG-DETAIL',
            'registry_date' => '2026-04-09',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.sales.show', $sale))
            ->assertOk()
            ->assertSee('REG-DETAIL')
            ->assertSee('2026-04')
            ->assertSee('There is no edit or delete');
    }

    #[Test]
    public function history_is_paginated(): void
    {
        // Dated explicitly rather than left to the factory's random range, so
        // the page size is what decides the result and not the calendar.
        RegistrySale::factory()->count(30)->create([
            'registry_date' => now()->toDateString(),
            'sale_date' => now()->toDateString(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.sales.index', ['per_page' => 25]))
            ->assertOk()
            ->assertSee('page=2', false)
            ->assertSee('Page 1 of 2')
            ->assertSee('25 per page');
    }

    #[Test]
    public function paging_keeps_the_filters(): void
    {
        $member = Member::factory()->create();

        RegistrySale::factory()->count(40)->forMember($member)->create([
            'registry_date' => now()->toDateString(),
            'sale_date' => now()->toDateString(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.sales.index', ['member_id' => $member->id, 'per_page' => 25, 'page' => 2]))
            ->assertOk()
            ->assertSee('member_id='.$member->id, false);
    }
}

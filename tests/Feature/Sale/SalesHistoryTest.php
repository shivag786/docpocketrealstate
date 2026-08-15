<?php

namespace Tests\Feature\Sale;

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
        $sale = RegistrySale::factory()->create(['registry_reference' => 'REG-FINDME']);

        $this->actingAs($this->admin)
            ->get(route('admin.sales.index'))
            ->assertOk()
            ->assertSee('REG-FINDME')
            ->assertSee($sale->member->member_code);
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
        config(['members.per_page' => 10]);

        RegistrySale::factory()->count(25)->create();

        $this->actingAs($this->admin)
            ->get(route('admin.sales.index'))
            ->assertOk()
            ->assertSee('page=2', false);
    }
}

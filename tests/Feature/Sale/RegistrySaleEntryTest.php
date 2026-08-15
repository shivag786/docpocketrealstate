<?php

namespace Tests\Feature\Sale;

use App\Enums\SaleStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Property;
use App\Models\RegistrySale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegistrySaleEntryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Member $member;

    private Project $project;

    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->member = Member::factory()->create();
        $this->project = Project::factory()->create();
        $this->property = Property::factory()->forProject($this->project)->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'member_id' => $this->member->id,
            'project_id' => $this->project->id,
            'property_id' => $this->property->id,
            'registry_reference' => 'REG-2026-0001',
            'registry_date' => now()->format('Y-m-d'),
            'sqft' => '1500.00',
            'notes' => null,
        ], $overrides);
    }

    #[Test]
    public function a_guest_cannot_record_a_sale(): void
    {
        $this->post(route('admin.sales.store'), $this->payload())
            ->assertRedirect(route('login'));

        $this->assertSame(0, RegistrySale::count());
    }

    #[Test]
    public function an_admin_can_record_a_sale(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.sales.store'), $this->payload())
            ->assertRedirect(route('admin.sales.create'));

        $sale = RegistrySale::firstWhere('registry_reference', 'REG-2026-0001');

        $this->assertNotNull($sale);
        $this->assertSame($this->member->id, $sale->member_id);
        $this->assertSame('1500.00', $sale->sqft);
    }

    #[Test]
    public function a_recorded_sale_is_approved_immediately(): void
    {
        // Client-confirmed: entry IS approval. There is no pending state.
        $this->actingAs($this->admin)
            ->post(route('admin.sales.store'), $this->payload());

        $sale = RegistrySale::first();

        $this->assertSame(SaleStatus::Approved, $sale->status);
        $this->assertSame(1, RegistrySale::approved()->count());
    }

    #[Test]
    public function the_recording_operator_is_stored(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.sales.store'), $this->payload());

        $this->assertSame($this->admin->id, RegistrySale::first()->entered_by);
    }

    #[Test]
    public function the_operator_cannot_be_spoofed_through_the_form(): void
    {
        $other = User::factory()->admin()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.sales.store'), $this->payload([
                'entered_by' => $other->id,
                'status' => 'something-else',
            ]));

        $sale = RegistrySale::first();

        $this->assertSame($this->admin->id, $sale->entered_by);
        $this->assertSame(SaleStatus::Approved, $sale->status);
    }

    #[Test]
    public function the_registry_date_decides_the_reward_period(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.sales.store'), $this->payload([
                'registry_date' => '2026-03-20',
                'sale_date' => '2026-02-10',
            ]));

        $sale = RegistrySale::first();

        // sale_date is February, but the period follows registry_date.
        $this->assertSame('2026-03', $sale->period());
        $this->assertSame(1, RegistrySale::forPeriod('2026-03')->count());
        $this->assertSame(0, RegistrySale::forPeriod('2026-02')->count());
    }

    #[Test]
    public function the_registry_date_defaults_to_today(): void
    {
        $payload = $this->payload();
        unset($payload['registry_date']);

        $this->actingAs($this->admin)->post(route('admin.sales.store'), $payload);

        $this->assertSame(
            now()->format('Y-m-d'),
            RegistrySale::first()->registry_date->format('Y-m-d')
        );
    }

    #[Test]
    public function the_sale_date_mirrors_the_registry_date_when_omitted(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.sales.store'), $this->payload(['registry_date' => '2026-05-04']));

        $sale = RegistrySale::first();

        $this->assertSame('2026-05-04', $sale->sale_date->format('Y-m-d'));
    }

    #[Test]
    public function a_future_registry_date_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.sales.store'), $this->payload([
                'registry_date' => now()->addDay()->format('Y-m-d'),
            ]))
            ->assertSessionHasErrors('registry_date');

        $this->assertSame(0, RegistrySale::count());
    }

    #[Test]
    public function a_duplicate_registry_number_is_rejected(): void
    {
        $this->actingAs($this->admin)->post(route('admin.sales.store'), $this->payload());

        $this->actingAs($this->admin)
            ->post(route('admin.sales.store'), $this->payload([
                'sqft' => '999.00',
            ]))
            ->assertSessionHasErrors('registry_reference');

        $this->assertSame(1, RegistrySale::count());
    }

    #[Test]
    public function only_member_and_sqft_are_required(): void
    {
        // Client correction: project, property, registry number and registry
        // date are optional supporting detail.
        $this->actingAs($this->admin)
            ->post(route('admin.sales.store'), [])
            ->assertSessionHasErrors(['member_id', 'sqft'])
            ->assertSessionDoesntHaveErrors(['project_id', 'property_id', 'registry_reference', 'registry_date']);

        $this->assertSame(0, RegistrySale::count());
    }

    #[Test]
    public function a_sale_can_be_recorded_with_only_a_member_and_sqft(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.sales.store'), [
                'member_id' => $this->member->id,
                'sqft' => '1500.00',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.sales.create'));

        $sale = RegistrySale::first();

        $this->assertNotNull($sale);
        $this->assertSame('1500.00', $sale->sqft);
        $this->assertNull($sale->project_id);
        $this->assertNull($sale->property_id);
        $this->assertNull($sale->registry_reference);

        // The reward month still resolves, using the entry day.
        $this->assertSame(now()->format('Y-m'), $sale->period());
    }

    #[Test]
    public function several_sales_may_omit_the_registry_number(): void
    {
        // A unique index would reject a second empty string; nulls must be fine.
        foreach (['100.00', '200.00', '300.00'] as $sqft) {
            $this->actingAs($this->admin)
                ->post(route('admin.sales.store'), [
                    'member_id' => $this->member->id,
                    'sqft' => $sqft,
                    'registry_reference' => '',
                ])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(3, RegistrySale::count());
        $this->assertSame(3, RegistrySale::whereNull('registry_reference')->count());
    }

    #[Test]
    public function optional_details_are_stored_when_supplied(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.sales.store'), $this->payload([
                'notes' => 'Corner plot, paid in full',
            ]))
            ->assertSessionHasNoErrors();

        $sale = RegistrySale::first();

        $this->assertSame($this->project->id, $sale->project_id);
        $this->assertSame($this->property->id, $sale->property_id);
        $this->assertSame('REG-2026-0001', $sale->registry_reference);
        $this->assertSame('Corner plot, paid in full', $sale->notes);
    }

    #[Test]
    public function a_property_cannot_be_recorded_without_its_project(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.sales.store'), [
                'member_id' => $this->member->id,
                'sqft' => '100.00',
                'property_id' => $this->property->id,
            ])
            ->assertSessionHasErrors('project_id');

        $this->assertSame(0, RegistrySale::count());
    }

    #[Test]
    public function a_project_may_be_recorded_without_a_property(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.sales.store'), [
                'member_id' => $this->member->id,
                'sqft' => '100.00',
                'project_id' => $this->project->id,
            ])
            ->assertSessionHasNoErrors();

        $sale = RegistrySale::first();

        $this->assertSame($this->project->id, $sale->project_id);
        $this->assertNull($sale->property_id);
    }

    #[Test]
    public function sqft_must_be_numeric(): void
    {
        foreach (['abc', '12abc', '1,500x', 'N/A', '--5'] as $value) {
            $this->actingAs($this->admin)
                ->post(route('admin.sales.store'), [
                    'member_id' => $this->member->id,
                    'sqft' => $value,
                ])
                ->assertSessionHasErrors('sqft');
        }

        $this->assertSame(0, RegistrySale::count());
    }

    #[Test]
    public function a_thousands_separator_in_sqft_is_accepted(): void
    {
        // Operators type "1,500.50" out of habit; strip it rather than reject it.
        $this->actingAs($this->admin)
            ->post(route('admin.sales.store'), [
                'member_id' => $this->member->id,
                'sqft' => '1,500.50',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('1500.50', RegistrySale::first()->sqft);
    }

    #[Test]
    public function sqft_must_be_greater_than_zero(): void
    {
        foreach (['0', '-100', '0.00'] as $value) {
            $this->actingAs($this->admin)
                ->post(route('admin.sales.store'), $this->payload([
                    'registry_reference' => 'REG-'.$value,
                    'sqft' => $value,
                ]))
                ->assertSessionHasErrors('sqft');
        }

        $this->assertSame(0, RegistrySale::count());
    }

    #[Test]
    public function sqft_is_limited_to_two_decimal_places(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.sales.store'), $this->payload(['sqft' => '1500.12345']))
            ->assertSessionHasErrors('sqft');
    }

    #[Test]
    public function sqft_keeps_exact_decimal_precision(): void
    {
        // This value later multiplies the reward rates; it must not drift.
        $this->actingAs($this->admin)
            ->post(route('admin.sales.store'), $this->payload(['sqft' => '1234.56']));

        $this->assertSame('1234.56', RegistrySale::first()->sqft);
    }

    #[Test]
    public function a_property_from_a_different_project_is_rejected(): void
    {
        // Optional does not mean unvalidated: a mismatched pair is still refused.
        $otherProject = Project::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.sales.store'), $this->payload([
                'project_id' => $otherProject->id,
            ]))
            ->assertSessionHasErrors('property_id');

        $this->assertSame(0, RegistrySale::count());
    }

    #[Test]
    public function an_inactive_property_cannot_be_sold(): void
    {
        $inactive = Property::factory()->forProject($this->project)->inactive()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.sales.store'), $this->payload([
                'property_id' => $inactive->id,
            ]))
            ->assertSessionHasErrors('property_id');
    }

    #[Test]
    public function an_inactive_member_cannot_have_a_sale_recorded(): void
    {
        $inactive = Member::factory()->inactive()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.sales.store'), $this->payload([
                'member_id' => $inactive->id,
            ]))
            ->assertSessionHasErrors('member_id');
    }

    #[Test]
    public function a_sale_cannot_be_edited_or_deleted(): void
    {
        // Client decision: registry sales are permanent. No such routes exist.
        $sale = RegistrySale::factory()->forProperty($this->property)->create();

        $this->assertFalse(app('router')->has('admin.sales.edit'));
        $this->assertFalse(app('router')->has('admin.sales.update'));
        $this->assertFalse(app('router')->has('admin.sales.destroy'));

        $this->actingAs($this->admin)
            ->put("/admin/sales/{$sale->id}")
            ->assertStatus(405);

        $this->actingAs($this->admin)
            ->delete("/admin/sales/{$sale->id}")
            ->assertStatus(405);
    }

    #[Test]
    public function the_entry_form_returns_ready_for_the_next_sale(): void
    {
        // The spec asks the form to stay ready for a run of entries.
        $this->actingAs($this->admin)
            ->post(route('admin.sales.store'), $this->payload())
            ->assertRedirect(route('admin.sales.create'))
            ->assertSessionHas('success');
    }
}

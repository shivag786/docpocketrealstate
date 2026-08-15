<?php

namespace Tests\Feature\Sale;

use App\Models\Project;
use App\Models\Property;
use App\Models\RegistrySale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProjectPropertyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    #[Test]
    public function a_guest_cannot_manage_projects(): void
    {
        $this->get(route('admin.projects.index'))->assertRedirect(route('login'));
        $this->get(route('admin.properties.index'))->assertRedirect(route('login'));
    }

    #[Test]
    public function a_project_can_be_created(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.projects.store'), [
                'name' => 'Green Valley',
                'location' => 'Indore',
                'description' => 'Phase one plots',
                'status' => 'active',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('projects', ['name' => 'Green Valley']);
    }

    #[Test]
    public function project_names_must_be_unique(): void
    {
        Project::factory()->create(['name' => 'Green Valley']);

        $this->actingAs($this->admin)
            ->post(route('admin.projects.store'), ['name' => 'Green Valley', 'status' => 'active'])
            ->assertSessionHasErrors('name');
    }

    #[Test]
    public function a_project_with_sales_cannot_be_deleted(): void
    {
        $sale = RegistrySale::factory()->create();

        $this->actingAs($this->admin)
            ->delete(route('admin.projects.destroy', $sale->project_id))
            ->assertRedirect();

        $this->assertDatabaseHas('projects', ['id' => $sale->project_id, 'deleted_at' => null]);
    }

    #[Test]
    public function a_project_with_properties_cannot_be_deleted(): void
    {
        $project = Project::factory()->create();
        Property::factory()->forProject($project)->create();

        $this->actingAs($this->admin)
            ->delete(route('admin.projects.destroy', $project))
            ->assertRedirect();

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'deleted_at' => null]);
    }

    #[Test]
    public function an_empty_project_can_be_deleted(): void
    {
        $project = Project::factory()->create();

        $this->actingAs($this->admin)
            ->delete(route('admin.projects.destroy', $project))
            ->assertRedirect(route('admin.projects.index'));

        $this->assertSoftDeleted($project);
    }

    #[Test]
    public function a_property_can_be_created_under_a_project(): void
    {
        $project = Project::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.properties.store'), [
                'project_id' => $project->id,
                'property_code' => 'PLOT-A1',
                'details' => 'Corner plot',
                'status' => 'active',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('properties', [
            'project_id' => $project->id,
            'property_code' => 'PLOT-A1',
        ]);
    }

    #[Test]
    public function a_property_code_is_unique_within_its_project_only(): void
    {
        $projectA = Project::factory()->create();
        $projectB = Project::factory()->create();

        Property::factory()->forProject($projectA)->create(['property_code' => 'PLOT-A1']);

        // Same code in a different project is fine.
        $this->actingAs($this->admin)
            ->post(route('admin.properties.store'), [
                'project_id' => $projectB->id,
                'property_code' => 'PLOT-A1',
                'status' => 'active',
            ])
            ->assertSessionHasNoErrors();

        // Same code in the same project is not.
        $this->actingAs($this->admin)
            ->post(route('admin.properties.store'), [
                'project_id' => $projectA->id,
                'property_code' => 'PLOT-A1',
                'status' => 'active',
            ])
            ->assertSessionHasErrors('property_code');
    }

    #[Test]
    public function a_property_with_sales_cannot_be_deleted(): void
    {
        $sale = RegistrySale::factory()->create();

        $this->actingAs($this->admin)
            ->delete(route('admin.properties.destroy', $sale->property_id))
            ->assertRedirect();

        $this->assertDatabaseHas('properties', ['id' => $sale->property_id, 'deleted_at' => null]);
    }

    #[Test]
    public function the_property_lookup_returns_only_active_sites_of_one_project(): void
    {
        $project = Project::factory()->create();
        $active = Property::factory()->forProject($project)->create(['property_code' => 'PLOT-ACTIVE']);
        Property::factory()->forProject($project)->inactive()->create(['property_code' => 'PLOT-OFF']);
        Property::factory()->create(['property_code' => 'PLOT-ELSEWHERE']);

        $response = $this->actingAs($this->admin)
            ->getJson(route('admin.properties.for-project', ['project_id' => $project->id]))
            ->assertOk()
            ->assertJsonPath('success', true);

        $codes = collect($response->json('data'))->pluck('property_code')->all();

        $this->assertSame(['PLOT-ACTIVE'], $codes);
        $this->assertSame($active->id, $response->json('data.0.id'));
    }

    #[Test]
    public function the_property_lookup_requires_a_valid_project(): void
    {
        $this->actingAs($this->admin)
            ->getJson(route('admin.properties.for-project', ['project_id' => 999999]))
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function only_active_projects_are_offered_on_the_sale_form(): void
    {
        Project::factory()->create(['name' => 'Live Project']);
        Project::factory()->inactive()->create(['name' => 'Dormant Project']);

        $this->actingAs($this->admin)
            ->get(route('admin.sales.create'))
            ->assertOk()
            ->assertSee('Live Project')
            ->assertDontSee('Dormant Project');
    }
}

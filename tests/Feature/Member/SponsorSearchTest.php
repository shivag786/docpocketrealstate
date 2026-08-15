<?php

namespace Tests\Feature\Member;

use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SponsorSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    #[Test]
    public function a_guest_cannot_search_sponsors(): void
    {
        $this->getJson(route('admin.members.search-sponsors', ['q' => 'test']))
            ->assertStatus(401);
    }

    #[Test]
    public function results_use_the_standard_ajax_envelope(): void
    {
        Member::factory()->create(['name' => 'Searchable Person']);

        $this->actingAs($this->admin)
            ->getJson(route('admin.members.search-sponsors', ['q' => 'Searchable']))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success', 'message', 'errors',
                'data' => [['id', 'member_code', 'name', 'mobile', 'status', 'status_label']],
            ]);
    }

    #[Test]
    public function a_member_can_be_found_by_name_code_or_mobile(): void
    {
        $member = Member::factory()->create([
            'name' => 'Rahul Sharma',
            'mobile' => '9876500001',
        ]);

        foreach (['Rahul', $member->member_code, '9876500001'] as $term) {
            $this->actingAs($this->admin)
                ->getJson(route('admin.members.search-sponsors', ['q' => $term]))
                ->assertOk()
                ->assertJsonPath('data.0.id', $member->id);
        }
    }

    #[Test]
    public function a_short_query_returns_nothing_rather_than_the_whole_network(): void
    {
        Member::factory()->count(5)->create();

        $this->actingAs($this->admin)
            ->getJson(route('admin.members.search-sponsors', ['q' => 'a']))
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    #[Test]
    public function results_are_capped(): void
    {
        config(['members.search_limit' => 5]);

        Member::factory()->count(12)->create(['name' => 'Common Name']);

        $this->actingAs($this->admin)
            ->getJson(route('admin.members.search-sponsors', ['q' => 'Common']))
            ->assertOk()
            ->assertJsonCount(5, 'data');
    }

    #[Test]
    public function the_edited_member_and_its_downline_are_excluded_from_results(): void
    {
        $root = Member::factory()->create(['name' => 'Chain Root']);
        $child = Member::factory()->sponsoredBy($root)->create(['name' => 'Chain Child']);
        $grandchild = Member::factory()->sponsoredBy($child)->create(['name' => 'Chain Grandchild']);
        $outsider = Member::factory()->create(['name' => 'Chain Outsider']);

        $response = $this->actingAs($this->admin)
            ->getJson(route('admin.members.search-sponsors', [
                'q' => 'Chain',
                'exclude' => $root->id,
            ]))
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertNotContains($root->id, $ids);
        $this->assertNotContains($child->id, $ids);
        $this->assertNotContains($grandchild->id, $ids);
        $this->assertContains($outsider->id, $ids);
    }
}

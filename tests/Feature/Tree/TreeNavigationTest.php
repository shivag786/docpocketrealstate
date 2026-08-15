<?php

namespace Tests\Feature\Tree;

use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TreeNavigationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    #[Test]
    public function a_guest_cannot_reach_the_tree(): void
    {
        $this->get(route('admin.tree.index'))->assertRedirect(route('login'));
    }

    #[Test]
    public function a_guest_cannot_reach_the_tree_endpoints(): void
    {
        $this->getJson(route('admin.tree.children'))->assertStatus(401);
        $this->getJson(route('admin.tree.search', ['q' => 'test']))->assertStatus(401);
    }

    #[Test]
    public function an_admin_can_open_the_tree_page(): void
    {
        Member::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.tree.index'))
            ->assertOk()
            ->assertSee('Sponsor Tree');
    }

    #[Test]
    public function the_tree_page_does_not_render_any_member_rows_itself(): void
    {
        // The whole point of lazy loading: the initial HTML carries no nodes.
        // They arrive only from the children endpoint.
        $member = Member::factory()->create(['name' => 'Should Not Be Inlined']);

        $this->actingAs($this->admin)
            ->get(route('admin.tree.index'))
            ->assertOk()
            ->assertDontSee('Should Not Be Inlined')
            ->assertDontSee($member->member_code);
    }

    #[Test]
    public function the_children_endpoint_returns_roots_when_no_member_is_given(): void
    {
        $root = Member::factory()->create();
        $child = Member::factory()->sponsoredBy($root)->create();

        $response = $this->actingAs($this->admin)
            ->getJson(route('admin.tree.children'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.level', 0);

        $ids = collect($response->json('data.nodes'))->pluck('id')->all();

        $this->assertContains($root->id, $ids);
        $this->assertNotContains($child->id, $ids);
    }

    #[Test]
    public function the_children_endpoint_returns_exactly_one_level(): void
    {
        $root = Member::factory()->create();
        $child = Member::factory()->sponsoredBy($root)->create();
        $grandchild = Member::factory()->sponsoredBy($child)->create();

        $response = $this->actingAs($this->admin)
            ->getJson(route('admin.tree.children', ['member_id' => $root->id]))
            ->assertOk()
            ->assertJsonPath('data.level', 1);

        $ids = collect($response->json('data.nodes'))->pluck('id')->all();

        $this->assertSame([$child->id], $ids);
        $this->assertNotContains($grandchild->id, $ids);
    }

    #[Test]
    public function a_node_carries_its_level_direct_count_and_branch_totals(): void
    {
        $root = Member::factory()->create();
        $child = Member::factory()->sponsoredBy($root)->create();
        Member::factory()->sponsoredBy($child)->create();
        Member::factory()->sponsoredBy($child)->inactive()->create();

        $node = collect(
            $this->actingAs($this->admin)
                ->getJson(route('admin.tree.children', ['member_id' => $root->id]))
                ->json('data.nodes')
        )->firstWhere('id', $child->id);

        $this->assertSame(1, $node['level']);
        $this->assertSame(2, $node['direct_count']);
        $this->assertSame(2, $node['team_total']);
        $this->assertSame(1, $node['team_active']);
        $this->assertTrue($node['is_team_leader']);
        $this->assertTrue($node['has_children']);
    }

    #[Test]
    public function a_leaf_node_reports_that_it_has_no_children(): void
    {
        $root = Member::factory()->create();
        $leaf = Member::factory()->sponsoredBy($root)->create();

        $node = collect(
            $this->actingAs($this->admin)
                ->getJson(route('admin.tree.children', ['member_id' => $root->id]))
                ->json('data.nodes')
        )->firstWhere('id', $leaf->id);

        $this->assertFalse($node['has_children']);
        $this->assertSame(0, $node['team_total']);
    }

    #[Test]
    public function an_unknown_member_id_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->getJson(route('admin.tree.children', ['member_id' => 999999]))
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function focus_returns_the_member_and_the_path_to_expand(): void
    {
        $root = Member::factory()->create();
        $child = Member::factory()->sponsoredBy($root)->create();
        $grandchild = Member::factory()->sponsoredBy($child)->create();

        $this->actingAs($this->admin)
            ->getJson(route('admin.tree.focus', $grandchild))
            ->assertOk()
            ->assertJsonPath('data.member.id', $grandchild->id)
            ->assertJsonPath('data.member.level', 2)
            ->assertJsonPath('data.path', [$root->id, $child->id]);
    }

    #[Test]
    public function tree_search_returns_matches_with_their_level(): void
    {
        $root = Member::factory()->create(['name' => 'Findable Root']);
        $child = Member::factory()->sponsoredBy($root)->create(['name' => 'Findable Child']);

        $response = $this->actingAs($this->admin)
            ->getJson(route('admin.tree.search', ['q' => 'Findable']))
            ->assertOk()
            ->assertJsonPath('success', true);

        $byId = collect($response->json('data'))->keyBy('id');

        $this->assertSame(0, $byId[$root->id]['level']);
        $this->assertSame(1, $byId[$child->id]['level']);
        $this->assertNull($byId[$root->id]['sponsor']);
        $this->assertSame($root->member_code, $byId[$child->id]['sponsor']['member_code']);
    }

    #[Test]
    public function a_short_tree_search_returns_nothing(): void
    {
        Member::factory()->count(5)->create();

        $this->actingAs($this->admin)
            ->getJson(route('admin.tree.search', ['q' => 'a']))
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    #[Test]
    public function the_downline_page_lists_descendants_with_levels(): void
    {
        $root = Member::factory()->create();
        $child = Member::factory()->sponsoredBy($root)->create(['name' => 'Level One']);
        $grandchild = Member::factory()->sponsoredBy($child)->create(['name' => 'Level Two']);

        $this->actingAs($this->admin)
            ->get(route('admin.tree.downline', $root))
            ->assertOk()
            ->assertSee('Level One')
            ->assertSee('Level Two')
            ->assertSee($grandchild->member_code);
    }

    #[Test]
    public function the_downline_page_can_be_filtered_by_depth(): void
    {
        $root = Member::factory()->create();
        $child = Member::factory()->sponsoredBy($root)->create(['name' => 'Shallow Person']);
        Member::factory()->sponsoredBy($child)->create(['name' => 'Deep Person']);

        $this->actingAs($this->admin)
            ->get(route('admin.tree.downline', [$root, 'max_level' => 1]))
            ->assertOk()
            ->assertSee('Shallow Person')
            ->assertDontSee('Deep Person');
    }

    #[Test]
    public function the_downline_page_paginates_rather_than_dumping_the_branch(): void
    {
        config(['members.per_page' => 10]);

        $root = Member::factory()->create();
        Member::factory()->count(25)->sponsoredBy($root)->create();

        $response = $this->actingAs($this->admin)
            ->get(route('admin.tree.downline', $root))
            ->assertOk()
            ->assertSee('25 members')
            // A second page must exist, proving the branch was not dumped whole.
            ->assertSee('page=2', false);

        // Only one page worth of rows is rendered.
        $this->assertSame(10, substr_count($response->getContent(), 'badge text-bg-light border">L1<'));
    }

    #[Test]
    public function the_member_profile_shows_the_tree_tabs(): void
    {
        $root = Member::factory()->create();
        Member::factory()->sponsoredBy($root)->create();

        $this->actingAs($this->admin)
            ->get(route('admin.members.show', $root))
            ->assertOk()
            ->assertSee('Overview')
            ->assertSee('Sponsor / Upline')
            ->assertSee('Direct Team')
            ->assertSee('Full Tree')
            ->assertSee('Total team');
    }

    #[Test]
    public function the_profile_marks_later_phase_tabs_as_unavailable(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.members.show', Member::factory()->create()))
            ->assertOk()
            ->assertSee('Reward Ledger')
            ->assertSee('Delivered in Phase 13');
    }

    #[Test]
    public function the_tree_page_can_start_focused_on_a_member(): void
    {
        $root = Member::factory()->create();
        $child = Member::factory()->sponsoredBy($root)->create();

        $this->actingAs($this->admin)
            ->get(route('admin.tree.index', ['member' => $child->id]))
            ->assertOk()
            ->assertSee('data-initial-focus="'.$child->id.'"', false);
    }
}

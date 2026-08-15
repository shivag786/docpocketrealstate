<?php

namespace Tests\Feature\Tree;

use App\Models\Member;
use App\Services\MemberTreeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemberTreeServiceTest extends TestCase
{
    use RefreshDatabase;

    private MemberTreeService $tree;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tree = app(MemberTreeService::class);
    }

    /**
     * Builds the sample network used across these tests:
     *
     *   rahul
     *   ├── a
     *   │   ├── a1
     *   │   └── a2
     *   │       └── a2x
     *   └── b
     *   other (separate root)
     *
     * @return array<string, Member>
     */
    private function sampleNetwork(): array
    {
        $rahul = Member::factory()->create(['name' => 'Rahul']);
        $a = Member::factory()->sponsoredBy($rahul)->create(['name' => 'A']);
        $b = Member::factory()->sponsoredBy($rahul)->create(['name' => 'B']);
        $a1 = Member::factory()->sponsoredBy($a)->create(['name' => 'A1']);
        $a2 = Member::factory()->sponsoredBy($a)->create(['name' => 'A2']);
        $a2x = Member::factory()->sponsoredBy($a2)->create(['name' => 'A2X']);
        $other = Member::factory()->create(['name' => 'Other Root']);

        return compact('rahul', 'a', 'b', 'a1', 'a2', 'a2x', 'other');
    }

    #[Test]
    public function roots_returns_only_members_without_a_sponsor(): void
    {
        ['rahul' => $rahul, 'other' => $other] = $this->sampleNetwork();

        $roots = $this->tree->roots();

        $this->assertEqualsCanonicalizing(
            [$rahul->id, $other->id],
            $roots->pluck('id')->all()
        );
    }

    #[Test]
    public function children_returns_one_level_only(): void
    {
        ['rahul' => $rahul, 'a' => $a, 'b' => $b, 'a1' => $a1] = $this->sampleNetwork();

        $children = $this->tree->children($rahul);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $children->pluck('id')->all());

        // A1 is a grandchild and must NOT appear — this is what keeps the tree lazy.
        $this->assertNotContains($a1->id, $children->pluck('id')->all());
    }

    #[Test]
    public function branch_totals_count_every_level_below_a_member(): void
    {
        ['rahul' => $rahul, 'a' => $a, 'a2' => $a2, 'b' => $b] = $this->sampleNetwork();

        $totals = $this->tree->branchTotals([$rahul->id, $a->id, $a2->id, $b->id]);

        // rahul -> a, b, a1, a2, a2x = 5
        $this->assertSame(5, $totals[$rahul->id]['total']);
        // a -> a1, a2, a2x = 3
        $this->assertSame(3, $totals[$a->id]['total']);
        // a2 -> a2x = 1
        $this->assertSame(1, $totals[$a2->id]['total']);
        // b is a leaf
        $this->assertSame(0, $totals[$b->id]['total']);
    }

    #[Test]
    public function branch_totals_exclude_the_member_itself_from_the_active_count(): void
    {
        $root = Member::factory()->create();
        Member::factory()->sponsoredBy($root)->create();
        Member::factory()->sponsoredBy($root)->inactive()->create();

        $totals = $this->tree->branchTotals([$root->id]);

        $this->assertSame(2, $totals[$root->id]['total']);
        $this->assertSame(1, $totals[$root->id]['active']);
    }

    #[Test]
    public function branch_totals_resolve_many_members_in_one_batch(): void
    {
        ['rahul' => $rahul, 'a' => $a, 'b' => $b, 'a1' => $a1] = $this->sampleNetwork();

        $totals = $this->tree->branchTotals([$rahul->id, $a->id, $b->id, $a1->id]);

        $this->assertCount(4, $totals);
        $this->assertArrayHasKey($a1->id, $totals);
        $this->assertSame(0, $totals[$a1->id]['total']);
    }

    #[Test]
    public function branch_totals_ignore_soft_deleted_members(): void
    {
        $root = Member::factory()->create();
        $keep = Member::factory()->sponsoredBy($root)->create();
        $gone = Member::factory()->sponsoredBy($root)->create();

        $gone->delete();

        $totals = $this->tree->branchTotals([$root->id]);

        $this->assertSame(1, $totals[$root->id]['total']);
        $this->assertNotNull($keep->fresh());
    }

    #[Test]
    public function branch_totals_returns_nothing_for_an_empty_request(): void
    {
        $this->assertSame([], $this->tree->branchTotals([]));
    }

    #[Test]
    public function level_is_the_number_of_ancestors(): void
    {
        ['rahul' => $rahul, 'a' => $a, 'a2' => $a2, 'a2x' => $a2x] = $this->sampleNetwork();

        $this->assertSame(0, $this->tree->levelOf($rahul));
        $this->assertSame(1, $this->tree->levelOf($a));
        $this->assertSame(2, $this->tree->levelOf($a2));
        $this->assertSame(3, $this->tree->levelOf($a2x));
    }

    #[Test]
    public function path_to_root_runs_outermost_first(): void
    {
        ['rahul' => $rahul, 'a' => $a, 'a2' => $a2, 'a2x' => $a2x] = $this->sampleNetwork();

        $this->assertSame(
            [$rahul->id, $a->id, $a2->id],
            $this->tree->pathToRoot($a2x)
        );
    }

    #[Test]
    public function path_to_root_is_empty_for_a_root_member(): void
    {
        $root = Member::factory()->create();

        $this->assertSame([], $this->tree->pathToRoot($root));
    }

    #[Test]
    public function downline_returns_every_descendant_with_its_level(): void
    {
        ['rahul' => $rahul, 'a' => $a, 'a2x' => $a2x] = $this->sampleNetwork();

        $downline = $this->tree->downline($rahul, perPage: 50);

        $this->assertSame(5, $downline->total());

        $levels = $downline->getCollection()->mapWithKeys(
            fn (Member $m) => [$m->id => $m->level]
        );

        $this->assertSame(1, $levels[$a->id]);
        $this->assertSame(3, $levels[$a2x->id]);
    }

    #[Test]
    public function downline_can_be_limited_by_level(): void
    {
        ['rahul' => $rahul] = $this->sampleNetwork();

        // Levels 1 and 2 only: a, b, a1, a2 — a2x sits at level 3.
        $this->assertSame(4, $this->tree->downline($rahul, perPage: 50, maxLevel: 2)->total());
        $this->assertSame(2, $this->tree->downline($rahul, perPage: 50, maxLevel: 1)->total());
    }

    #[Test]
    public function downline_is_paginated_rather_than_returned_whole(): void
    {
        $root = Member::factory()->create();
        Member::factory()->count(30)->sponsoredBy($root)->create();

        $page = $this->tree->downline($root, perPage: 10, page: 1);

        $this->assertSame(30, $page->total());
        $this->assertCount(10, $page->items());
        $this->assertSame(3, $page->lastPage());
    }

    #[Test]
    public function downline_excludes_soft_deleted_members(): void
    {
        $root = Member::factory()->create();
        Member::factory()->sponsoredBy($root)->create();
        $gone = Member::factory()->sponsoredBy($root)->create();
        $gone->delete();

        $this->assertSame(1, $this->tree->downline($root, perPage: 50)->total());
    }

    #[Test]
    public function downline_of_a_leaf_is_empty(): void
    {
        ['b' => $b] = $this->sampleNetwork();

        $this->assertSame(0, $this->tree->downline($b, perPage: 50)->total());
    }

    #[Test]
    public function search_reports_the_level_of_each_match(): void
    {
        ['a2x' => $a2x] = $this->sampleNetwork();

        $results = $this->tree->search('A2X');

        $this->assertCount(1, $results);
        $this->assertSame($a2x->id, $results[0]['id']);
        $this->assertSame(3, $results[0]['level']);
        $this->assertNotNull($results[0]['sponsor']);
    }

    #[Test]
    public function a_deep_chain_is_walked_correctly(): void
    {
        $previous = null;
        $chain = [];

        foreach (range(1, 25) as $i) {
            $previous = $previous === null
                ? Member::factory()->create()
                : Member::factory()->sponsoredBy($previous)->create();
            $chain[] = $previous;
        }

        $root = $chain[0];
        $deepest = end($chain);

        $this->assertSame(24, $this->tree->branchTotals([$root->id])[$root->id]['total']);
        $this->assertSame(24, $this->tree->levelOf($deepest));
        $this->assertSame(24, $this->tree->downline($root, perPage: 100)->total());
    }
}

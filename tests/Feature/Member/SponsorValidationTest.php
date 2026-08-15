<?php

namespace Tests\Feature\Member;

use App\Enums\MemberStatus;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/06_TESTING_AND_ACCEPTANCE.md — Sponsor validation:
 * "Self-sponsor blocked. Circular relationship blocked. Valid sponsor accepted."
 */
class SponsorValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(Member $member, array $overrides = []): array
    {
        return array_merge([
            'name' => $member->name,
            'mobile' => $member->mobile,
            'email' => $member->email,
            'address' => $member->address,
            'sponsor_id' => $member->sponsor_id,
            'joining_date' => $member->joining_date->format('Y-m-d'),
            'status' => $member->status->value,
        ], $overrides);
    }

    #[Test]
    public function a_valid_sponsor_is_accepted(): void
    {
        $sponsor = Member::factory()->create();
        $member = Member::factory()->create();

        $this->actingAs($this->admin)
            ->put(route('admin.members.update', $member), $this->payloadFor($member, [
                'sponsor_id' => $sponsor->id,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame($sponsor->id, $member->fresh()->sponsor_id);
    }

    #[Test]
    public function a_member_cannot_sponsor_themselves(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($this->admin)
            ->put(route('admin.members.update', $member), $this->payloadFor($member, [
                'sponsor_id' => $member->id,
            ]))
            ->assertSessionHasErrors('sponsor_id');

        $this->assertNull($member->fresh()->sponsor_id);
    }

    #[Test]
    public function a_direct_referral_cannot_become_the_sponsor(): void
    {
        $sponsor = Member::factory()->create();
        $child = Member::factory()->sponsoredBy($sponsor)->create();

        // Making the child the sponsor of its own sponsor closes a 2-node loop.
        $this->actingAs($this->admin)
            ->put(route('admin.members.update', $sponsor), $this->payloadFor($sponsor, [
                'sponsor_id' => $child->id,
            ]))
            ->assertSessionHasErrors('sponsor_id');

        $this->assertNull($sponsor->fresh()->sponsor_id);
    }

    #[Test]
    public function a_deep_descendant_cannot_become_the_sponsor(): void
    {
        $a = Member::factory()->create();
        $b = Member::factory()->sponsoredBy($a)->create();
        $c = Member::factory()->sponsoredBy($b)->create();
        $d = Member::factory()->sponsoredBy($c)->create();

        // A -> B -> C -> D. Assigning D as A's sponsor would close a 4-node loop.
        $this->actingAs($this->admin)
            ->put(route('admin.members.update', $a), $this->payloadFor($a, [
                'sponsor_id' => $d->id,
            ]))
            ->assertSessionHasErrors('sponsor_id');

        $this->assertNull($a->fresh()->sponsor_id);
    }

    #[Test]
    public function a_nonexistent_sponsor_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.members.store'), [
                'name' => 'Test Member',
                'mobile' => '9876543210',
                'joining_date' => now()->format('Y-m-d'),
                'status' => MemberStatus::Active->value,
                'sponsor_id' => 999999,
            ])
            ->assertSessionHasErrors('sponsor_id');
    }

    #[Test]
    public function a_sibling_is_a_valid_sponsor(): void
    {
        $parent = Member::factory()->create();
        $first = Member::factory()->sponsoredBy($parent)->create();
        $second = Member::factory()->sponsoredBy($parent)->create();

        // Siblings are unrelated vertically, so this must be allowed.
        $this->actingAs($this->admin)
            ->put(route('admin.members.update', $second), $this->payloadFor($second, [
                'sponsor_id' => $first->id,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame($first->id, $second->fresh()->sponsor_id);
    }

    #[Test]
    public function the_ancestor_chain_is_returned_nearest_first(): void
    {
        $a = Member::factory()->create();
        $b = Member::factory()->sponsoredBy($a)->create();
        $c = Member::factory()->sponsoredBy($b)->create();

        $ancestors = $c->ancestors();

        $this->assertSame([$b->id, $a->id], $ancestors->pluck('id')->all());
    }

    #[Test]
    public function the_ancestor_chain_can_be_limited(): void
    {
        $chain = [];
        $previous = null;

        foreach (range(1, 8) as $i) {
            $previous = $previous === null
                ? Member::factory()->create()
                : Member::factory()->sponsoredBy($previous)->create();
            $chain[] = $previous;
        }

        $deepest = end($chain);

        // Mirrors the upline rule's 5-level cap without hard-coding it here.
        $this->assertCount(5, $deepest->ancestors(5));
        $this->assertCount(7, $deepest->ancestors());
    }

    #[Test]
    public function descendant_ids_include_every_level(): void
    {
        $root = Member::factory()->create();
        $child = Member::factory()->sponsoredBy($root)->create();
        $grandchild = Member::factory()->sponsoredBy($child)->create();
        $unrelated = Member::factory()->create();

        $descendants = $root->descendantIds();

        $this->assertContains($child->id, $descendants);
        $this->assertContains($grandchild->id, $descendants);
        $this->assertNotContains($unrelated->id, $descendants);
        $this->assertNotContains($root->id, $descendants);
    }
}

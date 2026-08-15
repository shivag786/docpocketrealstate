<?php

namespace Tests\Feature\Member;

use App\Enums\MemberStatus;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemberCrudTest extends TestCase
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
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Rahul Sharma',
            'mobile' => '9876543210',
            'email' => 'rahul@example.test',
            'address' => '12 MG Road, Indore',
            'sponsor_id' => null,
            'joining_date' => now()->subMonth()->format('Y-m-d'),
            'status' => MemberStatus::Active->value,
        ], $overrides);
    }

    #[Test]
    public function a_guest_cannot_reach_the_member_list(): void
    {
        $this->get(route('admin.members.index'))->assertRedirect(route('login'));
    }

    #[Test]
    public function an_admin_can_view_the_member_list(): void
    {
        Member::factory()->create(['name' => 'Rahul Sharma']);

        $this->actingAs($this->admin)
            ->get(route('admin.members.index'))
            ->assertOk()
            ->assertSee('Rahul Sharma');
    }

    #[Test]
    public function a_member_can_be_created_without_a_sponsor(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.members.store'), $this->validPayload())
            ->assertRedirect();

        $member = Member::firstWhere('mobile', '9876543210');

        $this->assertNotNull($member);
        $this->assertNull($member->sponsor_id);
        $this->assertTrue($member->isRoot());
        $this->assertSame(MemberStatus::Active, $member->status);
    }

    #[Test]
    public function a_member_can_be_created_under_a_sponsor(): void
    {
        $sponsor = Member::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.members.store'), $this->validPayload([
                'sponsor_id' => $sponsor->id,
            ]))
            ->assertRedirect();

        $member = Member::firstWhere('mobile', '9876543210');

        $this->assertSame($sponsor->id, $member->sponsor_id);
        $this->assertTrue($sponsor->fresh()->isTeamLeader());
    }

    #[Test]
    public function name_mobile_and_joining_date_are_required(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.members.store'), [])
            ->assertSessionHasErrors(['name', 'mobile', 'joining_date']);

        $this->assertSame(0, Member::count());
    }

    #[Test]
    public function the_mobile_number_must_be_unique(): void
    {
        Member::factory()->create(['mobile' => '9876543210']);

        $this->actingAs($this->admin)
            ->post(route('admin.members.store'), $this->validPayload())
            ->assertSessionHasErrors('mobile');
    }

    #[Test]
    public function the_email_is_optional_but_must_be_unique_when_present(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.members.store'), $this->validPayload(['email' => null]))
            ->assertSessionHasNoErrors();

        $this->assertNull(Member::firstWhere('mobile', '9876543210')->email);

        Member::factory()->create(['email' => 'taken@example.test']);

        $this->actingAs($this->admin)
            ->post(route('admin.members.store'), $this->validPayload([
                'mobile' => '9000000001',
                'email' => 'taken@example.test',
            ]))
            ->assertSessionHasErrors('email');
    }

    #[Test]
    public function the_joining_date_cannot_be_in_the_future(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.members.store'), $this->validPayload([
                'joining_date' => now()->addDay()->format('Y-m-d'),
            ]))
            ->assertSessionHasErrors('joining_date');
    }

    #[Test]
    public function a_member_can_be_updated(): void
    {
        $member = Member::factory()->create(['name' => 'Old Name']);

        $this->actingAs($this->admin)
            ->put(route('admin.members.update', $member), $this->validPayload([
                'name' => 'New Name',
                'mobile' => $member->mobile,
                'email' => $member->email,
            ]))
            ->assertRedirect(route('admin.members.show', $member));

        $this->assertSame('New Name', $member->fresh()->name);
    }

    #[Test]
    public function the_member_code_cannot_be_changed_through_the_update_form(): void
    {
        $member = Member::factory()->create();
        $original = $member->member_code;

        $this->actingAs($this->admin)
            ->put(route('admin.members.update', $member), $this->validPayload([
                'member_code' => 'HACKED123',
                'mobile' => $member->mobile,
                'email' => $member->email,
            ]));

        $this->assertSame($original, $member->fresh()->member_code);
    }

    #[Test]
    public function a_member_without_referrals_can_be_soft_deleted(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($this->admin)
            ->delete(route('admin.members.destroy', $member))
            ->assertRedirect(route('admin.members.index'));

        $this->assertSoftDeleted($member);
    }

    #[Test]
    public function a_member_with_direct_referrals_cannot_be_deleted(): void
    {
        $sponsor = Member::factory()->create();
        Member::factory()->sponsoredBy($sponsor)->create();

        $this->actingAs($this->admin)
            ->delete(route('admin.members.destroy', $sponsor))
            ->assertRedirect();

        $this->assertNotSoftDeleted($sponsor);
    }

    #[Test]
    public function the_member_detail_page_lists_direct_referrals(): void
    {
        $sponsor = Member::factory()->create();
        $referral = Member::factory()->sponsoredBy($sponsor)->create(['name' => 'Direct Referral']);

        $this->actingAs($this->admin)
            ->get(route('admin.members.show', $sponsor))
            ->assertOk()
            ->assertSee('Direct Referral')
            ->assertSee($referral->member_code);
    }

    #[Test]
    public function the_list_can_be_searched_and_filtered(): void
    {
        Member::factory()->create(['name' => 'Findable Person', 'mobile' => '9111111111']);
        Member::factory()->create(['name' => 'Other Person', 'mobile' => '9222222222']);
        Member::factory()->inactive()->create(['name' => 'Dormant Person', 'mobile' => '9333333333']);

        $this->actingAs($this->admin)
            ->get(route('admin.members.index', ['q' => 'Findable']))
            ->assertOk()
            ->assertSee('Findable Person')
            ->assertDontSee('Other Person');

        $this->actingAs($this->admin)
            ->get(route('admin.members.index', ['status' => 'inactive']))
            ->assertOk()
            ->assertSee('Dormant Person')
            ->assertDontSee('Findable Person');
    }
}

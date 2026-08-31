<?php

namespace Tests\Feature\Member;

use App\Enums\MemberStatus;
use App\Models\Member;
use App\Models\User;
use App\Services\MemberCodeGenerator;
use App\Services\MemberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Client-confirmed: an admin-settable prefix followed by a plain sequential
 * number.
 */
class MemberCodeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Member',
            'mobile' => '9'.fake()->unique()->numerify('#########'),
            'email' => null,
            'address' => null,
            'sponsor_id' => null,
            'joining_date' => now()->format('Y-m-d'),
            'status' => MemberStatus::Active->value,
        ], $overrides);
    }

    /**
     * Pin the whole code format for one test.
     *
     * The prefix, the padding AND the starting number, together. Pinning only
     * the prefix left these tests reading the deployment's configured
     * `start_at`, so changing it to 101 (client, 2026-08-19) made five of them
     * fail while nothing about the mechanism had changed. A test of the
     * mechanism must not depend on a deployment setting.
     */
    private function fixCode(string $prefix, int $pad = 0, int $startAt = 1): void
    {
        config([
            'members.code.prefix' => $prefix,
            'members.code.pad' => $pad,
            'members.code.start_at' => $startAt,
        ]);
    }

    #[Test]
    public function the_configured_prefix_and_start_number_are_dprs_and_101(): void
    {
        // The live setting, asserted directly: the first member of a fresh
        // install is DPRS101.
        $this->assertSame('DPRS', config('members.code.prefix'));
        $this->assertSame(101, (int) config('members.code.start_at'));

        $member = app(MemberService::class)->create($this->payload());

        $this->assertSame('DPRS101', $member->member_code);
        $this->assertSame(101, $member->sequence_number);

        $second = app(MemberService::class)->create($this->payload());

        $this->assertSame('DPRS102', $second->member_code);
    }

    #[Test]
    public function codes_are_sequential_and_use_the_configured_prefix(): void
    {
        // start_at is pinned as well as the prefix. These tests are about the
        // MECHANISM, so they must not move when the deployment changes its
        // configured starting number (it is 101 in this project).
        $this->fixCode('RS', 0, 1);

        $service = app(MemberService::class);

        $first = $service->create($this->payload());
        $second = $service->create($this->payload());
        $third = $service->create($this->payload());

        $this->assertSame('RS1', $first->member_code);
        $this->assertSame('RS2', $second->member_code);
        $this->assertSame('RS3', $third->member_code);

        $this->assertSame([1, 2, 3], [
            $first->sequence_number,
            $second->sequence_number,
            $third->sequence_number,
        ]);
    }

    #[Test]
    public function the_prefix_is_admin_configurable(): void
    {
        $this->fixCode('ABC', 0, 1);

        $member = app(MemberService::class)->create($this->payload());

        $this->assertSame('ABC1', $member->member_code);
    }

    #[Test]
    public function padding_is_configurable(): void
    {
        $this->fixCode('RS', 5, 1);

        $member = app(MemberService::class)->create($this->payload());

        $this->assertSame('RS00001', $member->member_code);
    }

    #[Test]
    public function changing_the_prefix_continues_the_sequence_and_does_not_rewrite_issued_codes(): void
    {
        $service = app(MemberService::class);

        $this->fixCode('OLD', 0, 1);
        $first = $service->create($this->payload());

        config(['members.code.prefix' => 'NEW']);
        $second = $service->create($this->payload());

        // The already-issued code is a permanent identifier.
        $this->assertSame('OLD1', $first->fresh()->member_code);

        // Numbering continues rather than restarting, so no collision occurs.
        $this->assertSame('NEW2', $second->member_code);
        $this->assertSame(2, $second->sequence_number);
    }

    #[Test]
    public function a_soft_deleted_member_does_not_release_its_code(): void
    {
        $this->fixCode('RS', 0, 1);

        $service = app(MemberService::class);

        $first = $service->create($this->payload());
        $first->delete();

        $second = $service->create($this->payload());

        $this->assertSame('RS2', $second->member_code);
        $this->assertNotSame($first->member_code, $second->member_code);
    }

    #[Test]
    public function the_sequence_can_start_at_a_configured_number(): void
    {
        config([
            'members.code.prefix' => 'RS',
            'members.code.pad' => 0,
            'members.code.start_at' => 1001,
        ]);

        $member = app(MemberService::class)->create($this->payload());

        $this->assertSame('RS1001', $member->member_code);
        $this->assertSame(1001, $member->sequence_number);
    }

    #[Test]
    public function generated_codes_are_unique_across_many_members(): void
    {
        $service = app(MemberService::class);

        $codes = collect(range(1, 25))
            ->map(fn () => $service->create($this->payload())->member_code);

        $this->assertCount(25, $codes->unique());
    }

    #[Test]
    public function the_generator_formats_without_touching_the_database(): void
    {
        config(['members.code.prefix' => 'RS', 'members.code.pad' => 4]);

        $this->assertSame('RS0042', app(MemberCodeGenerator::class)->format(42));
    }

    #[Test]
    public function a_created_member_is_visible_with_its_code_in_the_admin_ui(): void
    {
        config(['members.code.prefix' => 'RS', 'members.code.pad' => 0]);

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.members.store'), $this->payload(['name' => 'Coded Member']))
            ->assertRedirect();

        $member = Member::firstWhere('name', 'Coded Member');

        $this->actingAs($admin)
            ->get(route('admin.members.show', $member))
            ->assertOk()
            ->assertSee($member->member_code);
    }
}

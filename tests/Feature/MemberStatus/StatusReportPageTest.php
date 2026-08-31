<?php

namespace Tests\Feature\MemberStatus;

use App\Models\Member;
use App\Models\RegistrySale;
use App\Models\User;
use App\Modules\MemberStatus\Services\StatusRecalculationService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;

/**
 * The optional report page (spec §27, §30).
 *
 * It is additive: a new page behind the same auth/role middleware as the rest
 * of the back office, added to no menu and replacing nothing.
 */
class StatusReportPageTest extends MemberStatusTestCase
{
    protected bool $reportEnabled = true;

    private Member $sponsor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sponsor = Member::factory()->root()->create([
            'name' => 'Shiva',
            'joining_date' => '2026-01-01',
        ]);

        RegistrySale::factory()->withoutDetails()->forMember($this->sponsor)
            ->create(['registry_date' => '2026-08-20', 'sale_date' => '2026-08-20']);

        app(StatusRecalculationService::class)->recalculateAll(CarbonImmutable::parse('2026-08-25'));
    }

    #[Test]
    public function an_admin_sees_every_members_calculated_status(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/member-status')
            ->assertOk()
            ->assertSee($this->sponsor->member_code)
            ->assertSee('Active')
            ->assertSee('20 Aug 2026');
    }

    #[Test]
    public function it_can_be_filtered_by_status_and_searched_by_member(): void
    {
        $other = Member::factory()->root()->create(['name' => 'Long gone', 'joining_date' => '2025-01-01']);

        app(StatusRecalculationService::class)->recalculateAll(CarbonImmutable::parse('2026-08-25'));

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/admin/member-status?status=INACTIVE')
            ->assertOk()
            ->assertSee($other->member_code)
            ->assertDontSee($this->sponsor->member_code);

        $this->actingAs($admin)
            ->get('/admin/member-status?q='.$this->sponsor->member_code)
            ->assertOk()
            ->assertSee($this->sponsor->member_code)
            ->assertDontSee($other->member_code);
    }

    #[Test]
    public function an_unrecognised_status_filter_falls_back_to_showing_everything(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/member-status?status=DROP+TABLE')
            ->assertOk()
            ->assertSee($this->sponsor->member_code);
    }

    #[Test]
    public function a_guest_cannot_see_member_data(): void
    {
        $this->get('/admin/member-status')->assertRedirect(route('login'));
    }
}

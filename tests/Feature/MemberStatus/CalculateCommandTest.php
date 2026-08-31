<?php

namespace Tests\Feature\MemberStatus;

use App\Models\Member;
use App\Models\RegistrySale;
use App\Modules\MemberStatus\Enums\CalculatedStatus;
use App\Modules\MemberStatus\Models\MemberStatusSnapshot;
use App\Modules\MemberStatus\Services\StatusRecalculationService;
use PHPUnit\Framework\Attributes\Test;

/**
 * The scheduled entry point, `member-status:calculate` (spec §23).
 */
class CalculateCommandTest extends MemberStatusTestCase
{
    private Member $sponsor;

    private Member $referral;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sponsor = Member::factory()->root()->create([
            'name' => 'Shiva',
            'joining_date' => '2026-01-01',
        ]);

        $this->referral = Member::factory()->sponsoredBy($this->sponsor)->create([
            'name' => 'A',
            'joining_date' => '2026-01-01',
        ]);

        RegistrySale::factory()->withoutDetails()->forMember($this->referral)
            ->create(['registry_date' => '2026-01-10', 'sale_date' => '2026-01-10']);
    }

    #[Test]
    public function it_calculates_every_member_and_stores_a_snapshot(): void
    {
        $this->artisan('member-status:calculate', ['--as-of' => '2026-01-20'])
            ->assertSuccessful();

        $this->assertSame(2, MemberStatusSnapshot::query()->count());

        $recalculation = app(StatusRecalculationService::class);

        $this->assertSame(CalculatedStatus::Active, $recalculation->currentStatus($this->sponsor->id));
        $this->assertSame(CalculatedStatus::Active, $recalculation->currentStatus($this->referral->id));
    }

    #[Test]
    public function it_judges_the_date_it_is_given_rather_than_today(): void
    {
        // 2026-06-01 is 142 days after the only sale: past 90, short of 180.
        $this->artisan('member-status:calculate', ['--as-of' => '2026-06-01'])
            ->assertSuccessful();

        $this->assertSame(
            CalculatedStatus::Pending,
            app(StatusRecalculationService::class)->currentStatus($this->sponsor->id),
        );
    }

    #[Test]
    public function a_dry_run_writes_nothing(): void
    {
        $this->artisan('member-status:calculate', ['--as-of' => '2026-06-01', '--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(0, MemberStatusSnapshot::query()->count());
    }

    #[Test]
    public function it_can_be_limited_to_specific_members(): void
    {
        $this->artisan('member-status:calculate', [
            '--as-of' => '2026-01-20',
            '--member' => [$this->referral->id],
        ])->assertSuccessful();

        $this->assertSame(1, MemberStatusSnapshot::query()->count());
        $this->assertSame(
            $this->referral->id,
            (int) MemberStatusSnapshot::query()->value('member_id'),
        );
    }

    #[Test]
    public function it_reports_an_unusable_as_of_date_instead_of_guessing(): void
    {
        $this->artisan('member-status:calculate', ['--as-of' => 'not-a-date'])
            ->assertFailed();

        $this->assertSame(0, MemberStatusSnapshot::query()->count());
    }

    #[Test]
    public function it_skips_ids_that_are_not_members(): void
    {
        $this->artisan('member-status:calculate', [
            '--as-of' => '2026-01-20',
            '--member' => [$this->referral->id, 999999],
        ])
            ->expectsOutputToContain('are not members')
            ->assertSuccessful();

        $this->assertSame(1, MemberStatusSnapshot::query()->count());
    }
}

<?php

namespace Tests\Feature\MemberStatus;

use App\Enums\MemberStatus;
use App\Models\Member;
use App\Models\RegistrySale;
use App\Modules\MemberStatus\Enums\CalculatedStatus;
use App\Modules\MemberStatus\Services\SaleActivityRecorder;
use App\Modules\MemberStatus\Services\StatusRecalculationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * The module runs; the existing tables do not move (spec §21, §37).
 *
 * Rows from `members` and `registry_sales` are captured before and compared
 * after — column for column, including `updated_at`. A single stray write shows
 * up here.
 *
 * SCOPE: this covers everything the module does on its own — calculating,
 * recording activity, running the batch. The one write it ever makes to an
 * existing table is admin-initiated and lives on the other side of a button:
 * confirming a payment updates `reward_ledger` through the application's own
 * RewardPaymentService. That path is covered by PaymentGateTest, not here.
 */
class HostDataUntouchedTest extends MemberStatusTestCase
{
    #[Test]
    public function a_full_recalculation_changes_no_row_in_members_or_registry_sales(): void
    {
        $sponsor = Member::factory()->root()->create(['joining_date' => '2026-01-01']);
        $referral = Member::factory()->sponsoredBy($sponsor)->create(['joining_date' => '2026-01-01']);

        RegistrySale::factory()->withoutDetails()->forMember($referral)
            ->create(['registry_date' => '2026-01-05', 'sale_date' => '2026-01-05']);

        $membersBefore = DB::table('members')->orderBy('id')->get()->toArray();
        $salesBefore = DB::table('registry_sales')->orderBy('id')->get()->toArray();

        // Everything the module can do: a full run, then a sale-driven run.
        app(StatusRecalculationService::class)->recalculateAll(CarbonImmutable::parse('2026-08-25'));
        app(SaleActivityRecorder::class)->recordSale(RegistrySale::query()->value('id'));

        $this->assertEquals($membersBefore, DB::table('members')->orderBy('id')->get()->toArray());
        $this->assertEquals($salesBefore, DB::table('registry_sales')->orderBy('id')->get()->toArray());
    }

    #[Test]
    public function the_applications_own_member_status_column_is_never_written(): void
    {
        // An application-Active member whom the module judges INACTIVE. The two
        // values are allowed to disagree — that is exactly why the module keeps
        // its own (spec §21).
        $member = Member::factory()->root()->create([
            'joining_date' => '2026-01-01',
            'status' => MemberStatus::Active,
        ]);

        app(StatusRecalculationService::class)->recalculateAll(CarbonImmutable::parse('2026-08-25'));

        $this->assertSame(
            CalculatedStatus::Inactive,
            app(StatusRecalculationService::class)->currentStatus($member->id),
        );

        $this->assertSame(
            MemberStatus::Active,
            $member->fresh()->status,
            'The module must not write members.status.'
        );
    }

    #[Test]
    public function soft_deleted_members_are_ignored_entirely(): void
    {
        $member = Member::factory()->root()->create(['joining_date' => '2026-01-01']);
        $member->delete();

        $summary = app(StatusRecalculationService::class)->recalculateAll(CarbonImmutable::parse('2026-08-25'));

        $this->assertSame(0, $summary->processed);
    }

    #[Test]
    public function calculating_status_writes_only_the_modules_own_three_tables(): void
    {
        $member = Member::factory()->root()->create(['joining_date' => '2026-01-01']);

        RegistrySale::factory()->withoutDetails()->forMember($member)
            ->create(['registry_date' => '2026-01-05', 'sale_date' => '2026-01-05']);

        $written = [];

        DB::listen(function ($query) use (&$written) {
            if (preg_match('/^\s*(insert|update|delete)\s/i', $query->sql) !== 1) {
                return;
            }

            if (preg_match('/`([a-z_]+)`/i', $query->sql, $matches) === 1) {
                $written[$matches[1]] = true;
            }
        });

        app(StatusRecalculationService::class)->recalculateAll(CarbonImmutable::parse('2026-08-25'));

        $this->assertEqualsCanonicalizing(
            ['member_status_activity', 'member_status_snapshot', 'member_status_history'],
            array_keys($written),
        );
    }
}

<?php

namespace Tests\Feature\Reward;

use App\Enums\CalculationRunStatus;
use App\Enums\CalculationRunType;
use App\Enums\LedgerStatus;
use App\Enums\RewardType;
use App\Models\CalculationRun;
use App\Models\Member;
use App\Models\RegistrySale;
use App\Models\RewardLedger;
use App\Models\TargetCalculation;
use App\Models\TeamCalculation;
use App\Models\User;
use App\Services\CompanyClubService;
use App\Services\PeriodRecalculationService;
use App\Services\RewardPaymentService;
use App\Services\TargetRewardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Figures follow the sales.
 *
 * Client-confirmed 2026-08-17: "everytime all calculation will be count of each
 * sale of every day, until month end." Entering a sale rebuilds its month across
 * every engine. What stops that is payment — a confirmed reward freezes the
 * amount and locks THAT ENGINE, and only that engine (client-confirmed
 * 2026-09-01). Company Club and Team Target are separate money with separate
 * approval, and their payment states are never mixed.
 *
 * These tests exist because the opposite behaviour was a real defect: five
 * August sales entered after the run had left ₹256,020 of direct rewards unpaid
 * and invisible.
 */
class RecalculationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private PeriodRecalculationService $recalc;

    private RewardPaymentService $payments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->recalc = app(PeriodRecalculationService::class);
        $this->payments = app(RewardPaymentService::class);
    }

    /** A past month, so payment is always available in these tests. */
    private const PERIOD = '2026-06';

    private function sell(Member $member, string $sqft, string $period = self::PERIOD): RegistrySale
    {
        return RegistrySale::factory()->forMember($member)->sqft($sqft)->inPeriod($period)->create();
    }

    private function directFor(Member $member, string $period = self::PERIOD): string
    {
        return (string) RewardLedger::where('member_id', $member->id)
            ->where('reward_type', RewardType::Direct)
            ->where('period', $period)
            ->sum('amount');
    }

    // -----------------------------------------------------------------
    // The defect this was built to fix
    // -----------------------------------------------------------------

    #[Test]
    public function a_sale_entered_after_a_calculation_is_picked_up_by_recalculating(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '1000.00');

        $this->recalc->recalculate(self::PERIOD, $this->admin);
        $this->assertSame('40000.00', $this->directFor($member));

        // The sale that used to vanish.
        $this->sell($member, '1200.00');
        $this->recalc->recalculate(self::PERIOD, $this->admin);

        // 2,200 x 40, not the stale 1,000 x 40.
        $this->assertSame('88000.00', $this->directFor($member));
    }

    #[Test]
    public function entering_a_sale_through_the_form_recalculates_its_month_immediately(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '1000.00');
        $this->recalc->recalculate(self::PERIOD, $this->admin);

        // No explicit recalculation anywhere in this request.
        $this->actingAs($this->admin)
            ->post(route('admin.sales.store'), [
                'member_id' => $member->id,
                'sqft' => '1500.00',
                'registry_date' => self::PERIOD.'-10',
            ])
            ->assertRedirect(route('admin.sales.create'))
            ->assertSessionHas('success');

        $this->assertSame('100000.00', $this->directFor($member), '2,500 x 40');
    }

    #[Test]
    public function recalculation_covers_every_engine_not_just_direct(): void
    {
        $leader = Member::factory()->create();
        $seller = Member::factory()->sponsoredBy($leader)->create();

        $this->sell($seller, '1000.00');
        $this->recalc->recalculate(self::PERIOD, $this->admin);

        $this->assertSame('1000.00', TeamCalculation::where('leader_id', $leader->id)->first()->total_team_sqft);
        $this->assertSame('1000.00', TargetCalculation::where('member_id', $leader->id)->first()->achieved_sqft);

        // A sale that lifts the leader's team over the target.
        $this->sell($seller, '4500.00');
        $this->recalc->recalculate(self::PERIOD, $this->admin);

        $this->assertSame('5500.00', TeamCalculation::where('leader_id', $leader->id)->first()->total_team_sqft);

        $verdict = TargetCalculation::where('member_id', $leader->id)->first();
        $this->assertSame('5500.00', $verdict->achieved_sqft);
        $this->assertTrue($verdict->achieved, 'The target flipped to achieved on recalculation.');

        // Upline moved too: pool is now 5,500 x 50 to the single upline.
        $this->assertSame('275000.00', (string) RewardLedger::where('member_id', $leader->id)
            ->where('reward_type', RewardType::Upline)->sum('amount'));
    }

    #[Test]
    public function recalculation_leaves_exactly_one_set_of_results_not_duplicates(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '1000.00');

        foreach (range(1, 3) as $ignored) {
            $this->recalc->recalculate(self::PERIOD, $this->admin);
        }

        $this->assertSame(1, RewardLedger::where('reward_type', RewardType::Direct)->count());
        $this->assertSame(1, TeamCalculation::count());
        $this->assertSame(1, TargetCalculation::count());
        $this->assertSame('40000.00', $this->directFor($member));
    }

    #[Test]
    public function a_target_achievement_can_disappear_again_while_the_month_is_unpaid(): void
    {
        // A member is only over the line because of one downline sale.
        $leader = Member::factory()->create();
        $seller = Member::factory()->sponsoredBy($leader)->create();

        $big = $this->sell($seller, '5000.00');
        $this->recalc->recalculate(self::PERIOD, $this->admin);

        $this->assertTrue(TargetCalculation::where('member_id', $leader->id)->first()->achieved);
        $this->assertSame(2, RewardLedger::where('reward_type', RewardType::Target)->count());

        // The sale is removed at the database level and the month rebuilt.
        $big->delete();
        $this->recalc->recalculate(self::PERIOD, $this->admin);

        $this->assertSame(0, TargetCalculation::count(), 'Nobody has sales, so nobody is measured.');
        $this->assertSame(0, RewardLedger::where('reward_type', RewardType::Target)->count());
    }

    // -----------------------------------------------------------------
    // Superseded runs
    // -----------------------------------------------------------------

    #[Test]
    public function previous_runs_are_superseded_rather_than_deleted(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '1000.00');

        $this->recalc->recalculate(self::PERIOD, $this->admin);
        $this->recalc->recalculate(self::PERIOD, $this->admin);

        $direct = CalculationRun::where('period', self::PERIOD)
            ->where('run_type', CalculationRunType::Direct)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $direct, 'Both attempts are kept as history.');
        $this->assertSame(CalculationRunStatus::Superseded, $direct[0]->status);
        $this->assertNotNull($direct[0]->superseded_at);
        $this->assertSame(CalculationRunStatus::Completed, $direct[1]->status);
    }

    #[Test]
    public function only_the_newest_run_holds_live_figures(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '1000.00');
        $this->recalc->recalculate(self::PERIOD, $this->admin);

        $this->sell($member, '500.00');
        $this->recalc->recalculate(self::PERIOD, $this->admin);

        $superseded = CalculationRun::where('status', CalculationRunStatus::Superseded)->pluck('id');

        $this->assertSame(
            0,
            RewardLedger::whereIn('calculation_run_id', $superseded)->count(),
            'A superseded run owns no ledger rows.'
        );
    }

    // -----------------------------------------------------------------
    // Payment is what makes a figure final
    // -----------------------------------------------------------------

    #[Test]
    public function a_reward_starts_unpaid(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '5000.00');
        $this->recalc->recalculate(self::PERIOD, $this->admin);

        $reward = RewardLedger::where('reward_type', RewardType::Target)->firstOrFail();

        $this->assertSame(LedgerStatus::Posted, $reward->status);
        $this->assertFalse($reward->isPaid());
        $this->assertNull($reward->paid_at);
        $this->assertNull($reward->paid_by);
    }

    #[Test]
    public function a_month_still_running_cannot_be_paid(): void
    {
        $current = now()->format('Y-m');

        $this->assertFalse($this->payments->periodIsPayable($current));
        $this->assertStringContainsString('has not finished', $this->payments->blockedReason($current));

        // A finished month is payable.
        $this->assertTrue($this->payments->periodIsPayable(self::PERIOD));
        $this->assertNull($this->payments->blockedReason(self::PERIOD));
    }

    #[Test]
    public function a_month_that_has_ended_still_waits_for_its_entry_window_to_close(): void
    {
        /*
         * Client-confirmed 2026-09-01. Month end is not the same as every sale
         * in the month having been entered: registry paperwork for the last
         * days arrives during the first days of the next month.
         *
         * This window is the whole fix for the problem that prompted the
         * change — a sale keyed in after payment lands against a locked engine
         * and can never be credited. Paying on the 1st guarantees that race;
         * waiting a few days removes it.
         */
        $this->travelTo(Carbon::parse('2026-07-02 09:00:00'));

        $this->assertSame(5, $this->payments->cutoffDays(), 'This test is written against the confirmed 5-day window.');
        $this->assertFalse($this->payments->periodIsPayable(self::PERIOD));
        $this->assertStringContainsString('entry window', $this->payments->blockedReason(self::PERIOD));
        $this->assertSame('06 Jul 2026', $this->payments->payableFrom(self::PERIOD)->format('d M Y'));

        // A sale arriving inside the window is absorbed exactly as normal —
        // which is the point of leaving it open.
        $member = Member::factory()->create();
        $this->sell($member, '1000.00');
        $this->recalc->recalculate(self::PERIOD, $this->admin);
        $this->assertSame('40000.00', $this->directFor($member));

        // On the cut-off day itself, payment opens.
        $this->travelTo(Carbon::parse('2026-07-06 09:00:00'));

        $this->assertTrue($this->payments->periodIsPayable(self::PERIOD));
        $this->assertNull($this->payments->blockedReason(self::PERIOD));
    }

    #[Test]
    public function the_entry_window_refuses_a_payment_rather_than_only_hiding_the_button(): void
    {
        $this->travelTo(Carbon::parse('2026-07-02 09:00:00'));

        $member = Member::factory()->create();
        $this->sell($member, '5000.00');
        $this->recalc->recalculate(self::PERIOD, $this->admin);

        $reward = RewardLedger::where('reward_type', RewardType::Target)->firstOrFail();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('entry window');

        $this->payments->pay($reward, $this->admin);
    }

    #[Test]
    public function marking_paid_records_who_confirmed_it_and_when(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '5000.00');
        $this->recalc->recalculate(self::PERIOD, $this->admin);

        $reward = RewardLedger::where('reward_type', RewardType::Target)->firstOrFail();
        $paid = $this->payments->pay($reward, $this->admin);

        $this->assertSame(LedgerStatus::Paid, $paid->status);
        $this->assertTrue($paid->isPaid());
        $this->assertNotNull($paid->paid_at);
        $this->assertSame($this->admin->id, $paid->paid_by);
    }

    #[Test]
    public function the_same_reward_cannot_be_paid_twice(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '5000.00');
        $this->recalc->recalculate(self::PERIOD, $this->admin);

        $reward = RewardLedger::where('reward_type', RewardType::Target)->firstOrFail();
        $this->payments->pay($reward, $this->admin);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already marked paid');

        $this->payments->pay($reward->refresh(), $this->admin);
    }

    #[Test]
    public function a_paid_reward_locks_its_own_engine_against_recalculation(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '5000.00');
        $this->recalc->recalculate(self::PERIOD, $this->admin);

        $paid = RewardLedger::where('reward_type', RewardType::Target)->firstOrFail();
        $this->payments->pay($paid, $this->admin);

        $amountBefore = (string) $paid->refresh()->amount;

        // A later sale that would otherwise change the verdict.
        $this->sell($member, '3000.00');
        $outcome = $this->recalc->recalculate(self::PERIOD, $this->admin);

        // Target refused, by name, and the paid amount is exactly as it was.
        $this->assertArrayNotHasKey('target', $outcome['completed']);
        $this->assertStringContainsString('Team Targets', implode(' ', $outcome['locked']));
        $this->assertSame($amountBefore, (string) $paid->refresh()->amount);

        // Running Target on its own still throws — an admin who asks for it
        // directly is owed the reason rather than a silent no-op.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is locked');

        app(TargetRewardService::class)->recalculate(self::PERIOD, $this->admin);
    }

    #[Test]
    public function paying_a_target_reward_leaves_direct_free_to_follow_its_sales(): void
    {
        /*
         * Client-confirmed 2026-09-01, and the reverse of what this file
         * asserted before. A paid Team Target used to freeze Direct, Upline and
         * Company Club with it, so a late sale could never be credited to the
         * member who made it — in an engine nobody had been paid from.
         *
         * Target money and Direct money are separate money. Each carries its own
         * lock, and neither reaches across to the other.
         */
        $upline = Member::factory()->create();
        $seller = Member::factory()->sponsoredBy($upline)->create();
        $this->sell($seller, '5000.00');
        $this->recalc->recalculate(self::PERIOD, $this->admin);

        $target = RewardLedger::where('reward_type', RewardType::Target)->firstOrFail();
        $this->payments->pay($target, $this->admin);

        $targetBefore = (string) $target->refresh()->amount;

        // A late sale lands in the month.
        $this->sell($seller, '900.00');
        $outcome = $this->recalc->recalculate(self::PERIOD, $this->admin);

        // Direct followed it: 5,900 x 40.
        $this->assertSame('236000.00', $this->directFor($seller));
        $this->assertArrayHasKey('direct', $outcome['completed']);

        // The paid Target amount did not move by a paisa.
        $this->assertSame($targetBefore, (string) $target->refresh()->amount);
        $this->assertStringContainsString('Team Targets', implode(' ', $outcome['locked']));
    }

    #[Test]
    public function paying_a_company_club_share_does_not_freeze_team_targets(): void
    {
        // The client's own example on 2026-09-01: Company Club payment status
        // and Team Target payment status must never be mixed.
        //
        // The seller needs a sponsor: the Club pays the seller's UPLINE, so a
        // root seller would build a pool with nobody eligible to receive it.
        $sponsor = Member::factory()->create();
        $member = Member::factory()->sponsoredBy($sponsor)->create();
        $this->sell($member, '5000.00');
        $this->recalc->recalculate(self::PERIOD, $this->admin);
        app(CompanyClubService::class)->calculate(self::PERIOD, $this->admin);

        $club = RewardLedger::where('reward_type', RewardType::CompanyClub)->firstOrFail();
        $this->payments->pay($club, $this->admin);

        $clubBefore = (string) $club->refresh()->amount;

        $this->sell($member, '2000.00');
        $outcome = $this->recalc->recalculate(self::PERIOD, $this->admin);

        // Target and Direct both rebuilt against 7,000 Sq.Ft...
        $this->assertArrayHasKey('target', $outcome['completed']);
        $this->assertSame('280000.00', $this->directFor($member));

        // ...and the paid Company Club share stayed exactly where it was.
        $this->assertSame($clubBefore, (string) $club->refresh()->amount);
        $this->assertStringContainsString('Company Club', implode(' ', $outcome['locked']));
    }

    #[Test]
    public function a_sale_into_a_partly_locked_month_is_recorded_and_the_frozen_engine_reported(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '5000.00');
        $this->recalc->recalculate(self::PERIOD, $this->admin);
        $this->payments->pay(
            RewardLedger::where('reward_type', RewardType::Target)->firstOrFail(),
            $this->admin
        );

        $before = RegistrySale::count();

        $this->actingAs($this->admin)
            ->post(route('admin.sales.store'), [
                'member_id' => $member->id,
                'sqft' => '750.00',
                'registry_date' => self::PERIOD.'-11',
            ])
            ->assertRedirect(route('admin.sales.create'))
            // The sale succeeded...
            ->assertSessionHas('success')
            // ...and the operator is told which engine did NOT move. A warning
            // rather than an error, because the rest of the month did absorb it.
            ->assertSessionHas('warning');

        $this->assertSame($before + 1, RegistrySale::count(), 'The sale is never lost.');

        // The unfrozen engines really did follow the sale: 5,750 x 40.
        $this->assertSame('230000.00', $this->directFor($member));
    }

    #[Test]
    public function mark_all_paid_confirms_every_outstanding_reward_in_the_month(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->sell(Member::factory()->create(), '5000.00');
        }

        $this->recalc->recalculate(self::PERIOD, $this->admin);

        $count = $this->payments->payAll(self::PERIOD, RewardType::Target, $this->admin);

        $this->assertSame(3, $count);
        $this->assertSame(0, RewardLedger::ofType(RewardType::Target)->unpaid()->count());
        $this->assertSame(3, RewardLedger::ofType(RewardType::Target)->paid()->count());
    }

    #[Test]
    public function the_payment_summary_reports_paid_and_outstanding_separately(): void
    {
        $a = Member::factory()->create();
        $b = Member::factory()->create();
        $this->sell($a, '5000.00');
        $this->sell($b, '6000.00');

        $this->recalc->recalculate(self::PERIOD, $this->admin);

        $this->payments->pay(
            RewardLedger::ofType(RewardType::Target)->where('member_id', $a->id)->firstOrFail(),
            $this->admin
        );

        $summary = $this->payments->summary(self::PERIOD, RewardType::Target);

        $this->assertSame(2, $summary['total']);
        $this->assertSame(1, $summary['paid']);
        $this->assertSame(1, $summary['unpaid']);
        $this->assertSame('50000.00', $summary['paid_amount']);
        $this->assertSame('50000.00', $summary['unpaid_amount']);
    }

    // -----------------------------------------------------------------
    // Stale detection
    // -----------------------------------------------------------------

    #[Test]
    public function a_month_that_drifts_reports_which_engine_cannot_follow(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '5000.00');
        $this->recalc->recalculate(self::PERIOD, $this->admin);
        $this->payments->pay(
            RewardLedger::where('reward_type', RewardType::Target)->firstOrFail(),
            $this->admin
        );

        $this->assertSame([], $this->recalc->stalePeriods(), 'Nothing has drifted yet.');

        // A sale the locked month cannot absorb.
        $this->sell($member, '400.00');

        $stale = $this->recalc->stalePeriods();

        $this->assertCount(1, $stale);
        $this->assertSame(self::PERIOD, $stale[0]['period']);
        $this->assertSame('5400.00', $stale[0]['live_sqft']);
        $this->assertSame('5000.00', $stale[0]['run_sqft']);

        // Only Team Targets is frozen. Naming it is the point: reporting the
        // whole month as locked would tell an operator that a rebuild is
        // pointless, when in fact everything but Target would follow the sale.
        $this->assertSame(['Team Targets'], $stale[0]['locked_engines']);
        $this->assertFalse($stale[0]['fully_locked']);
    }

    #[Test]
    public function an_up_to_date_month_is_never_reported_as_stale(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '1000.00');
        $this->recalc->recalculate(self::PERIOD, $this->admin);

        $this->assertSame([], $this->recalc->stalePeriods());
    }

    // -----------------------------------------------------------------
    // Screens
    // -----------------------------------------------------------------

    #[Test]
    public function the_mark_paid_button_is_disabled_while_the_month_is_running(): void
    {
        $current = now()->format('Y-m');
        $member = Member::factory()->create();
        $this->sell($member, '5000.00', $current);
        $this->recalc->recalculate($current, $this->admin);

        $this->actingAs($this->admin)
            ->get(route('admin.targets.achieved', ['period' => $current]))
            ->assertOk()
            ->assertSee('Mark paid')
            ->assertSee('disabled', false)
            ->assertSee('still running')
            ->assertSee('month not over');
    }

    #[Test]
    public function the_mark_paid_button_is_available_once_the_month_is_over(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '5000.00');
        $this->recalc->recalculate(self::PERIOD, $this->admin);

        $this->actingAs($this->admin)
            ->get(route('admin.targets.achieved', ['period' => self::PERIOD]))
            ->assertOk()
            ->assertSee('Mark paid')
            ->assertDontSee('month not over');
    }

    #[Test]
    public function marking_paid_through_the_screen_locks_the_month(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '5000.00');
        $this->recalc->recalculate(self::PERIOD, $this->admin);

        $reward = RewardLedger::where('reward_type', RewardType::Target)->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.targets.paid', $reward))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($this->admin)
            ->get(route('admin.targets.achieved', ['period' => self::PERIOD]))
            ->assertOk()
            ->assertSee('is locked')
            ->assertSee('Paid');
    }

    #[Test]
    public function the_screen_refuses_to_pay_a_month_that_is_still_running(): void
    {
        $current = now()->format('Y-m');
        $member = Member::factory()->create();
        $this->sell($member, '5000.00', $current);
        $this->recalc->recalculate($current, $this->admin);

        $reward = RewardLedger::where('reward_type', RewardType::Target)->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.targets.paid', $reward))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertFalse($reward->refresh()->isPaid());
    }

    #[Test]
    public function guests_cannot_mark_anything_paid_or_recalculate(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '5000.00');
        $this->recalc->recalculate(self::PERIOD, $this->admin);

        $reward = RewardLedger::where('reward_type', RewardType::Target)->firstOrFail();

        $this->post(route('admin.targets.paid', $reward))->assertRedirect(route('login'));
        $this->post(route('admin.targets.paid-all'), ['period' => self::PERIOD])->assertRedirect(route('login'));
        $this->post(route('admin.targets.recalculate'), ['period' => self::PERIOD])->assertRedirect(route('login'));

        $this->assertFalse($reward->refresh()->isPaid());
    }

    #[Test]
    public function only_target_rewards_can_be_paid_through_the_target_screen(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '5000.00');
        $this->recalc->recalculate(self::PERIOD, $this->admin);

        $direct = RewardLedger::where('reward_type', RewardType::Direct)->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.targets.paid', $direct))
            ->assertNotFound();
    }
}

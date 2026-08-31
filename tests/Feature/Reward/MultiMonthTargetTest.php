<?php

namespace Tests\Feature\Reward;

use App\Enums\LedgerStatus;
use App\Enums\RewardType;
use App\Enums\TargetLevel;
use App\Enums\TargetOutcome;
use App\Models\Member;
use App\Models\RegistrySale;
use App\Models\RewardLedger;
use App\Models\TargetCalculation;
use App\Models\User;
use App\Services\PeriodRecalculationService;
use App\Services\TargetRewardService;
use App\Services\TeamSalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Targets 2 and 3 — 10,000 over two months, 35,000 over three.
 *
 * Client-confirmed 2026-08-18. What separates these from Target 1 is that a
 * window spans more than one month, which introduces four rules a one-month
 * target never had to answer:
 *
 *   - progress ACCUMULATES across the months inside a window;
 *   - reaching the threshold PAYS IMMEDIATELY, even mid-window;
 *   - a window that closes short RESETS TO ZERO and a fresh block opens;
 *   - windows NEVER OVERLAP — a month belongs to exactly one attempt.
 *
 * Each has a test here, including the ones defined by what does not happen.
 */
class MultiMonthTargetTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private TargetRewardService $service;

    private TeamSalesService $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->service = app(TargetRewardService::class);
        $this->team = app(TeamSalesService::class);
    }

    private function sell(Member $member, string $sqft, string $period): void
    {
        RegistrySale::factory()->forMember($member)->sqft($sqft)->inPeriod($period)->create();
    }

    /** Team Sales is the input to the Target engine, so it always runs first. */
    private function judge(string ...$periods): void
    {
        foreach ($periods as $period) {
            $this->team->calculate($period, $this->admin);
            $this->service->calculate($period, $this->admin);
        }
    }

    private function verdict(Member $member, string $period): ?TargetCalculation
    {
        return TargetCalculation::where('member_id', $member->id)
            ->where('period', $period)
            ->first();
    }

    /** A member who has cleared Target 1 in January and is on Target 2 from February. */
    private function memberOnTargetTwo(): Member
    {
        $member = Member::factory()->create();
        $this->sell($member, '5000.00', '2026-01');

        return $member;
    }

    // -----------------------------------------------------------------
    // Opening the window
    // -----------------------------------------------------------------

    #[Test]
    public function target_two_opens_the_month_after_target_one_is_achieved(): void
    {
        $member = $this->memberOnTargetTwo();
        $this->sell($member, '1000.00', '2026-02');

        $this->judge('2026-01', '2026-02');

        $january = $this->verdict($member, '2026-01');
        $this->assertTrue($january->achieved);
        $this->assertSame(TargetLevel::One, $january->target_level);

        $february = $this->verdict($member, '2026-02');
        $this->assertSame(TargetLevel::Two, $february->target_level);
        $this->assertSame('10000.00', $february->target_sqft);
        // The window opens in FEBRUARY, not January — January is spent and paid, and
        // counting it again would be surplus rolling between targets.
        $this->assertSame('2026-02', $february->window_start);
        $this->assertSame('2026-03', $february->window_end);
        $this->assertSame('1000.00', $february->cumulative_sqft);
    }

    #[Test]
    public function a_member_still_on_target_one_never_gets_a_multi_month_window(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '2000.00', '2026-01');

        $this->judge('2026-01');

        $january = $this->verdict($member, '2026-01');
        $this->assertSame(TargetLevel::One, $january->target_level);
        $this->assertSame('2026-01', $january->window_start);
        $this->assertSame('2026-01', $january->window_end);
        $this->assertSame(TargetOutcome::Missed, $january->outcome());
    }

    // -----------------------------------------------------------------
    // Accumulation inside a window
    // -----------------------------------------------------------------

    #[Test]
    public function a_two_month_window_accumulates_across_its_months(): void
    {
        $member = $this->memberOnTargetTwo();
        $this->sell($member, '4000.00', '2026-02');
        $this->sell($member, '6000.00', '2026-03');

        $this->judge('2026-01', '2026-02', '2026-03');

        // Neither month reaches 10,000 alone. Together they do.
        $february = $this->verdict($member, '2026-02');
        $this->assertFalse($february->achieved);
        $this->assertSame(TargetOutcome::InProgress, $february->outcome());
        $this->assertSame('4000.00', $february->cumulative_sqft);
        $this->assertSame('6000.00', $february->shortfall_sqft);

        $march = $this->verdict($member, '2026-03');
        $this->assertTrue($march->achieved);
        $this->assertSame('6000.00', $march->achieved_sqft, 'This month alone.');
        $this->assertSame('10000.00', $march->cumulative_sqft, 'The window total.');
        $this->assertSame('200000.00', $march->reward_amount);
    }

    #[Test]
    public function a_quiet_month_inside_an_open_window_is_still_recorded(): void
    {
        $member = $this->memberOnTargetTwo();
        // Nothing at all in February; the window is open regardless.
        $this->sell($member, '3000.00', '2026-03');

        $this->judge('2026-01', '2026-02', '2026-03');

        $february = $this->verdict($member, '2026-02');
        $this->assertNotNull($february, 'A month inside an open window must be visible.');
        $this->assertSame('0.00', $february->cumulative_sqft);
        $this->assertSame(TargetOutcome::InProgress, $february->outcome());
        $this->assertSame(1, $february->monthsRemaining());
    }

    // -----------------------------------------------------------------
    // Reaching it early
    // -----------------------------------------------------------------

    #[Test]
    public function reaching_the_threshold_early_pays_immediately_and_opens_the_next_target(): void
    {
        $member = $this->memberOnTargetTwo();
        // The whole 10,000 in the FIRST month of a two-month window.
        $this->sell($member, '10000.00', '2026-02');

        $this->judge('2026-01', '2026-02', '2026-03');

        $february = $this->verdict($member, '2026-02');
        $this->assertTrue($february->achieved);
        $this->assertSame('200000.00', $february->reward_amount);
        // The window had a month left. It is not held open.
        $this->assertSame('2026-03', $february->window_end);

        $march = $this->verdict($member, '2026-03');
        $this->assertSame(TargetLevel::Three, $march->target_level);
        $this->assertSame('2026-03', $march->window_start);
        $this->assertSame('2026-05', $march->window_end, 'Three months, opening immediately.');
    }

    // -----------------------------------------------------------------
    // Missing it
    // -----------------------------------------------------------------

    #[Test]
    public function a_window_that_closes_short_resets_to_zero_and_a_fresh_block_opens(): void
    {
        $member = $this->memberOnTargetTwo();
        $this->sell($member, '3000.00', '2026-02');
        $this->sell($member, '3000.00', '2026-03');
        $this->sell($member, '1000.00', '2026-04');

        $this->judge('2026-01', '2026-02', '2026-03', '2026-04');

        $march = $this->verdict($member, '2026-03');
        $this->assertFalse($march->achieved);
        $this->assertSame(TargetOutcome::Missed, $march->outcome(), 'The window closed short.');
        $this->assertSame('6000.00', $march->cumulative_sqft);

        $april = $this->verdict($member, '2026-04');
        $this->assertSame(TargetLevel::Two, $april->target_level, 'Same target, no penalty.');
        $this->assertSame('2026-04', $april->window_start, 'A fresh block, not a rolling window.');
        $this->assertSame('2026-05', $april->window_end);
        // The 6,000 from the failed window is gone.
        $this->assertSame('1000.00', $april->cumulative_sqft);
    }

    #[Test]
    public function windows_never_overlap_so_a_month_belongs_to_exactly_one_attempt(): void
    {
        $member = $this->memberOnTargetTwo();
        // 6,000 then 5,000. A rolling two-month window would find 11,000 in
        // Mar+Apr and pay. Fixed blocks must not: Feb+Mar is one attempt that
        // closed at 6,000, and April starts again from zero.
        $this->sell($member, '3000.00', '2026-02');
        $this->sell($member, '6000.00', '2026-03');
        $this->sell($member, '5000.00', '2026-04');

        $this->judge('2026-01', '2026-02', '2026-03', '2026-04');

        $this->assertFalse($this->verdict($member, '2026-03')->achieved);

        $april = $this->verdict($member, '2026-04');
        $this->assertFalse($april->achieved, 'March must not be counted a second time.');
        $this->assertSame('5000.00', $april->cumulative_sqft);
        $this->assertSame(TargetOutcome::InProgress, $april->outcome());

        $this->assertSame('50000.00', RewardLedger::query()
            ->where('member_id', $member->id)
            ->where('reward_type', RewardType::Target)
            ->sum('amount'), 'Only the Target 1 reward has been earned.');
    }

    // -----------------------------------------------------------------
    // The reward
    // -----------------------------------------------------------------

    #[Test]
    public function the_reward_is_fixed_at_the_threshold_on_every_target(): void
    {
        $member = $this->memberOnTargetTwo();
        // 12,000 against a 10,000 target.
        $this->sell($member, '12000.00', '2026-02');

        $this->judge('2026-01', '2026-02');

        $february = $this->verdict($member, '2026-02');
        $this->assertSame('200000.00', $february->reward_amount, 'The Target 2 prize is fixed: 12,000 wins the same as 10,000.');
        $this->assertSame('2000.00', $february->surplusSqft());
        $this->assertSame('10000.00', $february->target_sqft);
    }

    #[Test]
    public function the_surplus_from_target_two_does_not_carry_into_target_three(): void
    {
        $member = $this->memberOnTargetTwo();
        $this->sell($member, '12000.00', '2026-02');
        $this->sell($member, '500.00', '2026-03');

        $this->judge('2026-01', '2026-02', '2026-03');

        $march = $this->verdict($member, '2026-03');
        $this->assertSame(TargetLevel::Three, $march->target_level);
        $this->assertSame('500.00', $march->cumulative_sqft, 'The 2,000 surplus was discarded.');
    }

    // -----------------------------------------------------------------
    // The whole ladder
    // -----------------------------------------------------------------

    #[Test]
    public function a_member_can_climb_the_whole_ladder_and_is_then_never_measured_again(): void
    {
        $member = Member::factory()->create();

        $this->sell($member, '5000.00', '2026-01');   // Target 1
        $this->sell($member, '10000.00', '2026-02');  // Target 2, first month
        $this->sell($member, '35000.00', '2026-03');  // Target 3, first month
        $this->sell($member, '90000.00', '2026-04');  // nothing left to win

        $this->judge('2026-01', '2026-02', '2026-03', '2026-04');

        $this->assertSame(TargetLevel::One, $this->verdict($member, '2026-01')->target_level);
        $this->assertSame(TargetLevel::Two, $this->verdict($member, '2026-02')->target_level);
        $this->assertSame(TargetLevel::Three, $this->verdict($member, '2026-03')->target_level);

        $this->assertTrue($this->verdict($member, '2026-03')->achieved);
        $this->assertNull(
            $this->verdict($member, '2026-04'),
            'The ladder is finished, so the member is no longer measured.'
        );

        // Each target paid exactly once, at its own threshold.
        $rewards = RewardLedger::query()
            ->where('member_id', $member->id)
            ->where('reward_type', RewardType::Target)
            ->orderBy('id')
            ->pluck('amount')
            ->all();

        $this->assertSame(['50000.00', '200000.00', '700000.00'], $rewards);
    }

    #[Test]
    public function target_three_accumulates_over_three_months(): void
    {
        $member = $this->memberOnTargetTwo();
        $this->sell($member, '10000.00', '2026-02');   // clears Target 2

        // Target 3 window: Mar, Apr, May.
        $this->sell($member, '15000.00', '2026-03');
        $this->sell($member, '15000.00', '2026-04');
        $this->sell($member, '5000.00', '2026-05');

        $this->judge('2026-01', '2026-02', '2026-03', '2026-04', '2026-05');

        $this->assertSame('15000.00', $this->verdict($member, '2026-03')->cumulative_sqft);
        $this->assertSame('30000.00', $this->verdict($member, '2026-04')->cumulative_sqft);

        $may = $this->verdict($member, '2026-05');
        $this->assertSame('35000.00', $may->cumulative_sqft);
        $this->assertTrue($may->achieved);
        $this->assertSame('700000.00', $may->reward_amount);
        $this->assertSame('2026-03', $may->window_start);
        $this->assertSame('2026-05', $may->window_end);
    }

    // -----------------------------------------------------------------
    // Back-dating, which is what makes the replay necessary
    // -----------------------------------------------------------------

    #[Test]
    public function back_dating_a_sale_re_judges_every_month_after_it(): void
    {
        $member = Member::factory()->create();

        $this->sell($member, '2000.00', '2026-01');
        $this->sell($member, '2000.00', '2026-02');
        $this->judge('2026-01', '2026-02');

        // Nobody has achieved anything: 2,000 in each of two one-month windows.
        $this->assertFalse($this->verdict($member, '2026-01')->achieved);
        $this->assertSame(TargetLevel::One, $this->verdict($member, '2026-02')->target_level);

        // A January sale turns up late and pushes January over Target 1. February is not
        // about January, but its verdict depends on it: the member is on Target 2
        // from February onward, which no amount of re-reading February alone can tell.
        $this->sell($member, '3000.00', '2026-01');
        app(PeriodRecalculationService::class)->recalculate('2026-01', $this->admin);

        $this->assertTrue($this->verdict($member, '2026-01')->achieved);

        $february = $this->verdict($member, '2026-02');
        $this->assertSame(TargetLevel::Two, $february->target_level, 'February was re-judged, not left stale.');
        $this->assertSame('2026-02', $february->window_start);
        $this->assertSame('2000.00', $february->cumulative_sqft);
    }

    #[Test]
    public function the_cascade_is_refused_when_a_later_month_holds_a_paid_reward(): void
    {
        $member = Member::factory()->create();

        $this->sell($member, '5000.00', '2026-01');    // Target 1
        $this->sell($member, '10000.00', '2026-02');   // Target 2, paid in February
        $this->judge('2026-01', '2026-02');

        $paid = RewardLedger::query()->where('period', '2026-02')->update([
            'status' => LedgerStatus::Paid,
            'paid_at' => now(),
        ]);

        $this->assertSame(1, $paid, 'February must hold the Target 2 reward for this test to mean anything.');

        $this->sell($member, '1000.00', '2026-01');

        // Rebuilding January would move February, which has been paid. Refused outright
        // rather than half-applied.
        $this->expectException(RuntimeException::class);
        app(PeriodRecalculationService::class)->recalculate('2026-01', $this->admin);
    }

    #[Test]
    public function the_engine_refuses_when_an_earlier_month_with_sales_was_never_rolled_up(): void
    {
        $member = Member::factory()->create();
        $this->sell($member, '4000.00', '2026-01');
        $this->sell($member, '4000.00', '2026-02');

        // Only February is rolled up. January would silently count as zero and could
        // turn an achievement into a miss.
        $this->team->calculate('2026-02', $this->admin);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('2026-01');

        $this->service->calculate('2026-02', $this->admin);
    }
}

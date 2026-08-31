<?php

namespace Tests\Unit\MemberStatus;

use App\Modules\MemberStatus\Data\MemberRecord;
use App\Modules\MemberStatus\Data\QualifyingActivity;
use App\Modules\MemberStatus\Data\SaleRecord;
use App\Modules\MemberStatus\Enums\ActivityType;
use App\Modules\MemberStatus\Enums\CalculatedStatus;
use App\Modules\MemberStatus\Services\MemberStatusEngine;
use App\Modules\MemberStatus\Support\StatusConfig;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The 90/180 rules themselves (spec §26, tests 4-9).
 *
 * No framework, no database, no application: the engine is a pure function, and
 * proving the thresholds this way means the rules are verified independently of
 * how sales happen to be stored. The tree rules (tests 1-3) need real data and
 * live in tests/Feature/MemberStatus.
 */
class MemberStatusEngineTest extends TestCase
{
    private const DAY_ZERO = '2026-01-01';

    private function engine(array $overrides = []): MemberStatusEngine
    {
        return new MemberStatusEngine((new StatusConfig)->with($overrides));
    }

    private function member(string $joinedAt = self::DAY_ZERO): MemberRecord
    {
        return new MemberRecord(
            id: 1,
            sponsorId: null,
            joinedAt: CarbonImmutable::parse($joinedAt)->startOfDay(),
            name: 'Shiva',
            code: 'DPRS101',
        );
    }

    /** Qualifying activity on a given day offset from day zero. */
    private function activityOnDay(int $day, ActivityType $type = ActivityType::OwnSale): QualifyingActivity
    {
        $sale = new SaleRecord(
            id: 900 + $day,
            memberId: $type === ActivityType::OwnSale ? 1 : 2,
            soldAt: CarbonImmutable::parse(self::DAY_ZERO)->addDays($day),
        );

        return $type === ActivityType::OwnSale
            ? QualifyingActivity::ownSale(1, $sale)
            : QualifyingActivity::directReferralSale(1, $sale);
    }

    private function dayOf(int $day): CarbonImmutable
    {
        return CarbonImmutable::parse(self::DAY_ZERO)->addDays($day);
    }

    // -----------------------------------------------------------------
    // The threshold table (spec §1: 0-89 ACTIVE, 90-179 PENDING, 180+ INACTIVE)
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{int, CalculatedStatus}>
     */
    public static function dayCounts(): array
    {
        return [
            'day 0 — sold today' => [0, CalculatedStatus::Active],
            'day 89 — last active day' => [89, CalculatedStatus::Active],
            'day 90 — first pending day' => [90, CalculatedStatus::Pending],
            'day 179 — last pending day' => [179, CalculatedStatus::Pending],
            'day 180 — first inactive day' => [180, CalculatedStatus::Inactive],
            'day 400 — long gone' => [400, CalculatedStatus::Inactive],
        ];
    }

    #[Test]
    #[DataProvider('dayCounts')]
    public function it_maps_days_of_inactivity_to_a_status(int $days, CalculatedStatus $expected): void
    {
        $this->assertSame($expected, $this->engine()->statusForDays($days));
    }

    #[Test]
    public function test_4_ninety_days_without_activity_turns_active_into_pending(): void
    {
        $result = $this->engine()->calculate(
            $this->member(),
            $this->activityOnDay(0),
            CalculatedStatus::Active,
            $this->dayOf(90),
        );

        $this->assertSame(CalculatedStatus::Pending, $result->status);
        $this->assertSame(90, $result->daysSinceActivity);
        $this->assertStringContainsString('No qualifying activity for 90 days', $result->reason);
    }

    #[Test]
    public function test_5_one_hundred_and_eighty_days_without_activity_turns_pending_into_inactive(): void
    {
        $engine = $this->engine();
        $member = $this->member();
        $activity = $this->activityOnDay(0);

        $this->assertSame(
            CalculatedStatus::Active,
            $engine->calculate($member, $activity, null, $this->dayOf(1))->status,
        );

        $this->assertSame(
            CalculatedStatus::Pending,
            $engine->calculate($member, $activity, CalculatedStatus::Active, $this->dayOf(95))->status,
        );

        $this->assertSame(
            CalculatedStatus::Inactive,
            $engine->calculate($member, $activity, CalculatedStatus::Pending, $this->dayOf(180))->status,
        );
    }

    #[Test]
    public function test_6_a_direct_referral_sale_during_the_pending_window_restores_active(): void
    {
        // Day 0 sale, PENDING at day 90, referral sells on day 120.
        $result = $this->engine()->calculate(
            $this->member(),
            $this->activityOnDay(120, ActivityType::DirectReferralSale),
            CalculatedStatus::Pending,
            $this->dayOf(120),
        );

        $this->assertSame(CalculatedStatus::Active, $result->status);
        $this->assertSame(0, $result->daysSinceActivity);
        $this->assertStringContainsString('Direct referral property sale', $result->reason);
    }

    #[Test]
    public function test_7_later_activity_resets_the_clock_rather_than_extending_the_first(): void
    {
        // Sales on day 0 and day 60. On day 100 the member is 40 days past the
        // SECOND sale, not 100 days past the first, so they are still ACTIVE.
        $result = $this->engine()->calculate(
            $this->member(),
            $this->activityOnDay(60),
            CalculatedStatus::Active,
            $this->dayOf(100),
        );

        $this->assertSame(CalculatedStatus::Active, $result->status);
        $this->assertSame(40, $result->daysSinceActivity);
    }

    #[Test]
    public function test_8_activity_on_day_179_prevents_inactive(): void
    {
        $result = $this->engine()->calculate(
            $this->member(),
            $this->activityOnDay(179),
            CalculatedStatus::Pending,
            $this->dayOf(179),
        );

        $this->assertSame(CalculatedStatus::Active, $result->status);
        $this->assertNotSame(CalculatedStatus::Inactive, $result->status);
    }

    #[Test]
    public function test_9_reactivation_from_inactive_is_configurable(): void
    {
        $member = $this->member();
        $activity = $this->activityOnDay(200);
        $asOf = $this->dayOf(200);

        $allowed = $this->engine(['allowInactiveReactivation' => true])
            ->calculate($member, $activity, CalculatedStatus::Inactive, $asOf);

        $this->assertSame(CalculatedStatus::Active, $allowed->status);
        $this->assertFalse($allowed->reactivationBlocked);

        $blocked = $this->engine(['allowInactiveReactivation' => false])
            ->calculate($member, $activity, CalculatedStatus::Inactive, $asOf);

        $this->assertSame(CalculatedStatus::Inactive, $blocked->status);
        $this->assertTrue($blocked->reactivationBlocked);
    }

    #[Test]
    public function pending_always_returns_to_active_on_activity_even_when_reactivation_is_switched_off(): void
    {
        // The configuration gates INACTIVE -> ACTIVE only. PENDING -> ACTIVE is
        // the business rule and is not negotiable (spec §17).
        $result = $this->engine(['allowInactiveReactivation' => false])
            ->calculate($this->member(), $this->activityOnDay(120), CalculatedStatus::Pending, $this->dayOf(120));

        $this->assertSame(CalculatedStatus::Active, $result->status);
    }

    // -----------------------------------------------------------------
    // New members (spec §10)
    // -----------------------------------------------------------------

    #[Test]
    public function a_member_who_just_joined_and_has_no_sales_is_active(): void
    {
        $result = $this->engine()->calculate($this->member('2026-01-01'), null, null, $this->dayOf(3));

        $this->assertSame(CalculatedStatus::Active, $result->status);
        $this->assertTrue($result->hasNeverBeenActive());
        $this->assertSame(3, $result->daysSinceActivity);
    }

    #[Test]
    public function a_member_with_no_sales_is_measured_from_their_joining_date_not_from_forever(): void
    {
        $member = $this->member('2026-01-01');

        $this->assertSame(
            CalculatedStatus::Pending,
            $this->engine()->calculate($member, null, null, $this->dayOf(90))->status,
        );

        $this->assertSame(
            CalculatedStatus::Inactive,
            $this->engine()->calculate($member, null, null, $this->dayOf(180))->status,
        );
    }

    #[Test]
    public function a_member_is_never_inactive_before_they_joined(): void
    {
        // Judged a week BEFORE the joining date: the day count floors at zero
        // rather than going negative and wrapping into a status.
        $result = $this->engine()->calculate($this->member('2026-01-01'), null, null, $this->dayOf(-7));

        $this->assertSame(CalculatedStatus::Active, $result->status);
        $this->assertSame(0, $result->daysSinceActivity);
    }

    #[Test]
    public function activity_dated_before_the_joining_date_cannot_push_the_clock_back(): void
    {
        // Defensive: a back-dated sale imported against a member who joined
        // later must not make them retroactively inactive.
        $result = $this->engine()->calculate(
            $this->member('2026-06-01'),
            $this->activityOnDay(0),
            null,
            CarbonImmutable::parse('2026-06-10'),
        );

        $this->assertSame(CalculatedStatus::Active, $result->status);
        $this->assertSame(9, $result->daysSinceActivity);
    }

    #[Test]
    public function the_grace_period_delays_the_first_decay_for_new_members(): void
    {
        $engine = $this->engine(['newMemberGraceDays' => 30]);

        $this->assertSame(
            CalculatedStatus::Active,
            $engine->calculate($this->member(), null, null, $this->dayOf(100))->status,
        );

        $this->assertSame(
            CalculatedStatus::Pending,
            $engine->calculate($this->member(), null, null, $this->dayOf(121))->status,
        );
    }

    // -----------------------------------------------------------------
    // Configuration (spec §29)
    // -----------------------------------------------------------------

    #[Test]
    public function the_thresholds_come_from_configuration_and_are_not_hard_coded(): void
    {
        $engine = $this->engine(['activePeriodDays' => 30, 'pendingPeriodDays' => 15]);

        $this->assertSame(45, $engine->config()->inactiveThresholdDays());
        $this->assertSame(CalculatedStatus::Active, $engine->statusForDays(29));
        $this->assertSame(CalculatedStatus::Pending, $engine->statusForDays(30));
        $this->assertSame(CalculatedStatus::Pending, $engine->statusForDays(44));
        $this->assertSame(CalculatedStatus::Inactive, $engine->statusForDays(45));
    }

    #[Test]
    public function the_shipped_defaults_are_ninety_and_one_hundred_and_eighty_days(): void
    {
        $config = StatusConfig::fromArray(require __DIR__.'/../../../app/Modules/MemberStatus/Config/member_status.php');

        $this->assertSame(90, $config->activePeriodDays);
        $this->assertSame(90, $config->pendingPeriodDays);
        $this->assertSame(180, $config->inactiveThresholdDays());
    }

    #[Test]
    public function a_status_does_not_depend_on_the_time_of_day_the_calculation_runs(): void
    {
        $engine = $this->engine();
        $member = $this->member();
        $activity = $this->activityOnDay(0);

        $morning = $engine->calculate($member, $activity, null, CarbonImmutable::parse('2026-03-31 06:00:00'));
        $midnight = $engine->calculate($member, $activity, null, CarbonImmutable::parse('2026-03-31 23:59:59'));

        $this->assertSame($morning->status, $midnight->status);
        $this->assertSame($morning->daysSinceActivity, $midnight->daysSinceActivity);
    }
}

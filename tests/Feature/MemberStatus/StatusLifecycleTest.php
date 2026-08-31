<?php

namespace Tests\Feature\MemberStatus;

use App\Models\Member;
use App\Models\RegistrySale;
use App\Modules\MemberStatus\Enums\CalculatedStatus;
use App\Modules\MemberStatus\Models\MemberStatusHistory;
use App\Modules\MemberStatus\Models\MemberStatusSnapshot;
use App\Modules\MemberStatus\Services\StatusRecalculationService;
use App\Modules\MemberStatus\Support\StatusConfig;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;

/**
 * The full ACTIVE -> PENDING -> INACTIVE -> ACTIVE lifecycle against real data,
 * with the snapshot and history tables checked at every step (spec §20-§23).
 *
 * Time is moved with explicit `as of` dates rather than by travelling, because
 * that is exactly how the command is meant to be re-run over a past date to
 * reproduce a decision.
 */
class StatusLifecycleTest extends MemberStatusTestCase
{
    private Member $sponsor;

    private Member $referral;

    private StatusRecalculationService $recalculation;

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

        $this->recalculation = app(StatusRecalculationService::class);
    }

    private function on(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date)->startOfDay();
    }

    private function sell(Member $member, string $date): RegistrySale
    {
        return RegistrySale::factory()
            ->withoutDetails()
            ->forMember($member)
            ->create(['registry_date' => $date, 'sale_date' => $date]);
    }

    private function statusOn(string $date): CalculatedStatus
    {
        $this->recalculation->recalculateAll($this->on($date));

        return $this->recalculation->currentStatus($this->sponsor->id);
    }

    #[Test]
    public function a_member_walks_from_active_through_pending_to_inactive_and_back(): void
    {
        // Day 0 — own sale.
        $this->sell($this->sponsor, '2026-01-01');

        $this->assertSame(CalculatedStatus::Active, $this->statusOn('2026-01-02'));

        // Day 89 — still the last active day.
        $this->assertSame(CalculatedStatus::Active, $this->statusOn('2026-03-31'));

        // Day 90 — PENDING.
        $this->assertSame(CalculatedStatus::Pending, $this->statusOn('2026-04-01'));

        // Day 179 — still PENDING.
        $this->assertSame(CalculatedStatus::Pending, $this->statusOn('2026-06-29'));

        // Day 180 — INACTIVE.
        $this->assertSame(CalculatedStatus::Inactive, $this->statusOn('2026-06-30'));

        // A direct referral sells: the sponsor comes back (spec §17, default
        // configuration allows reactivation from INACTIVE).
        $this->sell($this->referral, '2026-07-10');

        $this->assertSame(CalculatedStatus::Active, $this->statusOn('2026-07-11'));

        // Every move is on the record, in order, with a readable reason.
        $history = MemberStatusHistory::query()
            ->where('member_id', $this->sponsor->id)
            ->orderBy('id')
            ->get();

        $this->assertSame(
            ['ACTIVE', 'PENDING', 'INACTIVE', 'ACTIVE'],
            $history->pluck('new_status')->map(fn ($s) => $s->value)->all(),
        );

        $this->assertStringContainsString('No qualifying activity for 90 days', $history[1]->reason);
        $this->assertStringContainsString('No qualifying activity for 180 days', $history[2]->reason);
        $this->assertStringContainsString('Direct referral property sale', $history[3]->reason);
    }

    #[Test]
    public function a_recalculation_that_changes_nothing_writes_no_history(): void
    {
        $this->sell($this->sponsor, '2026-01-01');

        $this->recalculation->recalculateAll($this->on('2026-01-02'));
        $this->recalculation->recalculateAll($this->on('2026-01-03'));
        $this->recalculation->recalculateAll($this->on('2026-01-04'));

        $this->assertSame(
            1,
            MemberStatusHistory::query()->where('member_id', $this->sponsor->id)->count(),
            'Only the first calculation is a change; confirming the same status is not.'
        );
    }

    #[Test]
    public function the_snapshot_separates_when_the_status_changed_from_when_it_was_calculated(): void
    {
        $this->sell($this->sponsor, '2026-01-01');

        $this->recalculation->recalculateAll($this->on('2026-04-01')); // becomes PENDING
        $this->recalculation->recalculateAll($this->on('2026-04-20')); // still PENDING

        $snapshot = MemberStatusSnapshot::query()->where('member_id', $this->sponsor->id)->firstOrFail();

        $this->assertSame(CalculatedStatus::Pending, $snapshot->status);
        $this->assertSame('2026-04-01', $snapshot->status_changed_at->toDateString());
        $this->assertSame('2026-04-20', $snapshot->calculated_at->toDateString());
        $this->assertSame('2026-01-01', $snapshot->last_activity_at->toDateString());
        $this->assertSame(109, $snapshot->days_since_activity);
    }

    #[Test]
    public function a_new_member_with_no_sales_starts_active_and_decays_from_their_joining_date(): void
    {
        $newcomer = Member::factory()->root()->create(['joining_date' => '2026-08-01']);

        $this->recalculation->recalculateAll($this->on('2026-08-25'));

        $snapshot = MemberStatusSnapshot::query()->where('member_id', $newcomer->id)->firstOrFail();

        $this->assertSame(CalculatedStatus::Active, $snapshot->status);
        $this->assertNull($snapshot->last_activity_at);
        $this->assertSame('2026-08-01', $snapshot->reference_date->toDateString());
        $this->assertSame(24, $snapshot->days_since_activity);

        // 90 days after joining, with still no sale, they fall to PENDING.
        $this->recalculation->recalculateAll($this->on('2026-10-30'));

        $this->assertSame(
            CalculatedStatus::Pending,
            $this->recalculation->currentStatus($newcomer->id),
        );
    }

    #[Test]
    public function reactivation_from_inactive_can_be_switched_off(): void
    {
        config()->set('member_status.allow_inactive_reactivation', false);
        $this->refreshApplicationBindings();

        $this->sell($this->sponsor, '2026-01-01');

        $recalculation = app(StatusRecalculationService::class);
        $recalculation->recalculateAll($this->on('2026-06-30'));

        $this->assertSame(CalculatedStatus::Inactive, $recalculation->currentStatus($this->sponsor->id));

        $this->sell($this->sponsor, '2026-07-10');
        $recalculation->recalculateAll($this->on('2026-07-11'));

        $this->assertSame(
            CalculatedStatus::Inactive,
            $recalculation->currentStatus($this->sponsor->id),
            'With reactivation disabled a new sale must not lift an INACTIVE member.'
        );
    }

    #[Test]
    public function a_dry_run_calculates_everything_and_writes_nothing(): void
    {
        $this->sell($this->sponsor, '2026-01-01');

        $summary = $this->recalculation->recalculateAll($this->on('2026-04-01'), persist: false);

        // Both members are PENDING on this date: the sponsor is 90 days past
        // his own sale, and the referral has never sold and is 90 days past
        // joining.
        $this->assertSame(2, $summary->processed);
        $this->assertSame(2, $summary->total(CalculatedStatus::Pending));
        $this->assertSame(2, $summary->changed());

        $this->assertSame(0, MemberStatusSnapshot::query()->count());
        $this->assertSame(0, MemberStatusHistory::query()->count());
    }

    /**
     * Rebuild the module's singletons so a config change made mid-test is seen.
     * StatusConfig is resolved once and held, which is the behaviour we want in
     * production and the one thing a test has to work around.
     */
    private function refreshApplicationBindings(): void
    {
        $this->app->forgetInstance(StatusConfig::class);
    }
}

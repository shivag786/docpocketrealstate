<?php

namespace Tests\Feature\MemberStatus;

use App\Models\Member;
use App\Models\RegistrySale;
use App\Modules\MemberStatus\Contracts\PropertySaleProvider;
use App\Modules\MemberStatus\Enums\ActivityType;
use App\Modules\MemberStatus\Enums\CalculatedStatus;
use App\Modules\MemberStatus\Models\MemberStatusActivity;
use App\Modules\MemberStatus\Services\SaleActivityRecorder;
use App\Modules\MemberStatus\Services\StatusRecalculationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * Spec §26 tests 1-3 and §19: WHO a sale makes active.
 *
 * This is the rule the whole module exists for. A sale is activity for the
 * seller and for the seller's DIRECT sponsor, and for nobody else — not the
 * grandparent, not the root, not anyone further up.
 *
 * The tree used throughout, which is the specification's own example:
 *
 *      Shiva
 *      ├── A
 *      │   └── A1
 *      │       └── A2
 *      └── B
 */
class QualifyingActivityTest extends MemberStatusTestCase
{
    private Member $shiva;

    private Member $a;

    private Member $b;

    private Member $a1;

    private Member $a2;

    private SaleActivityRecorder $recorder;

    private StatusRecalculationService $recalculation;

    protected function setUp(): void
    {
        parent::setUp();

        $joined = '2026-01-01';

        $this->shiva = Member::factory()->root()->create(['name' => 'Shiva', 'joining_date' => $joined]);
        $this->a = Member::factory()->sponsoredBy($this->shiva)->create(['name' => 'A', 'joining_date' => $joined]);
        $this->b = Member::factory()->sponsoredBy($this->shiva)->create(['name' => 'B', 'joining_date' => $joined]);
        $this->a1 = Member::factory()->sponsoredBy($this->a)->create(['name' => 'A1', 'joining_date' => $joined]);
        $this->a2 = Member::factory()->sponsoredBy($this->a1)->create(['name' => 'A2', 'joining_date' => $joined]);

        $this->recorder = app(SaleActivityRecorder::class);
        $this->recalculation = app(StatusRecalculationService::class);
    }

    private function sellOn(Member $member, string $date): RegistrySale
    {
        return RegistrySale::factory()
            ->withoutDetails()
            ->forMember($member)
            ->create(['registry_date' => $date, 'sale_date' => $date]);
    }

    private function activityDatesFor(Member $member): array
    {
        return MemberStatusActivity::query()
            ->where('member_id', $member->id)
            ->get()
            ->map(fn ($row) => $row->activity_type->value.'@'.$row->activity_date->toDateString())
            ->sort()
            ->values()
            ->all();
    }

    #[Test]
    public function test_1_a_members_own_sale_makes_them_active(): void
    {
        $sale = $this->sellOn($this->shiva, '2026-08-20');

        $this->recorder->recordSale($sale->id, CarbonImmutable::parse('2026-08-25'));

        $this->assertSame(
            CalculatedStatus::Active,
            $this->recalculation->currentStatus($this->shiva->id),
        );

        $this->assertSame(
            ['OWN_SALE@2026-08-20'],
            $this->activityDatesFor($this->shiva),
        );
    }

    #[Test]
    public function test_2_a_direct_referrals_sale_makes_the_sponsor_active_too(): void
    {
        $sale = $this->sellOn($this->a, '2026-08-20');

        $this->recorder->recordSale($sale->id, CarbonImmutable::parse('2026-08-25'));

        $this->assertSame(CalculatedStatus::Active, $this->recalculation->currentStatus($this->a->id));
        $this->assertSame(CalculatedStatus::Active, $this->recalculation->currentStatus($this->shiva->id));

        $this->assertSame(['DIRECT_REFERRAL_SALE@2026-08-20'], $this->activityDatesFor($this->shiva));
        $this->assertSame(['OWN_SALE@2026-08-20'], $this->activityDatesFor($this->a));
    }

    #[Test]
    public function test_3_a_level_two_sale_does_not_reach_the_grandparent(): void
    {
        // A1 sells. A1 and A are affected. Shiva is NOT (spec §19).
        $sale = $this->sellOn($this->a1, '2026-08-20');

        $outcomes = $this->recorder->recordSale($sale->id, CarbonImmutable::parse('2026-08-25'));

        $this->assertCount(2, $outcomes, 'A sale must affect exactly the seller and their direct sponsor.');

        $affected = array_map(fn ($outcome) => $outcome->member->id, $outcomes);
        $this->assertEqualsCanonicalizing([$this->a1->id, $this->a->id], $affected);

        $this->assertSame([], $this->activityDatesFor($this->shiva));
        $this->assertSame([], $this->activityDatesFor($this->b));

        $this->assertNull(
            $this->recalculation->currentStatus($this->shiva->id),
            'Shiva must not even be recalculated by a level-2 sale.'
        );
    }

    #[Test]
    public function a_level_three_sale_reaches_only_two_levels_either(): void
    {
        // A2 sells: A2 and A1 only. A and Shiva untouched.
        $sale = $this->sellOn($this->a2, '2026-08-20');

        $this->recorder->recordSale($sale->id, CarbonImmutable::parse('2026-08-25'));

        $this->assertSame(['OWN_SALE@2026-08-20'], $this->activityDatesFor($this->a2));
        $this->assertSame(['DIRECT_REFERRAL_SALE@2026-08-20'], $this->activityDatesFor($this->a1));
        $this->assertSame([], $this->activityDatesFor($this->a));
        $this->assertSame([], $this->activityDatesFor($this->shiva));
    }

    #[Test]
    public function one_sale_by_any_direct_referral_is_enough_for_the_sponsor(): void
    {
        // Spec §4: Shiva needs one qualifying sale from himself or ANY direct
        // referral. B selling is enough even though A never sold.
        $sale = $this->sellOn($this->b, '2026-08-20');

        $this->recorder->recordSale($sale->id, CarbonImmutable::parse('2026-08-25'));

        $this->assertSame(CalculatedStatus::Active, $this->recalculation->currentStatus($this->shiva->id));
    }

    #[Test]
    public function the_provider_reports_the_newest_activity_of_each_kind(): void
    {
        $this->sellOn($this->shiva, '2026-05-10');
        $this->sellOn($this->shiva, '2026-06-15');
        $this->sellOn($this->a, '2026-07-20');
        $this->sellOn($this->b, '2026-08-01');
        $this->sellOn($this->a1, '2026-08-24'); // level 2 — must be invisible to Shiva

        $sales = app(PropertySaleProvider::class);

        $byType = $sales->getLatestActivityByTypeForMany([$this->shiva->id]);

        $this->assertSame(
            '2026-06-15',
            $byType[$this->shiva->id][ActivityType::OwnSale->value]->activityDate->toDateString(),
        );

        $referral = $byType[$this->shiva->id][ActivityType::DirectReferralSale->value];

        $this->assertSame('2026-08-01', $referral->activityDate->toDateString());
        $this->assertSame($this->b->id, $referral->sourceMemberId, 'B made the newest direct-referral sale.');

        // And the single "latest of either kind" answer is the referral sale.
        $latest = $sales->getLastQualifyingActivity($this->shiva->id);

        $this->assertSame(ActivityType::DirectReferralSale, $latest->type);
        $this->assertSame('2026-08-01', $latest->activityDate->toDateString());
    }

    #[Test]
    public function only_sales_with_a_qualifying_status_count(): void
    {
        $sale = $this->sellOn($this->shiva, '2026-08-20');

        // Simulate a state the host application does not have today: the
        // module must ignore anything outside the configured list (spec §11).
        // Written with the query builder so no application model is involved.
        DB::table('registry_sales')
            ->where('id', $sale->id)
            ->update(['status' => 'cancelled']);

        $this->assertNull(app(PropertySaleProvider::class)->findValidSale($sale->id));
        $this->assertSame([], $this->recorder->recordSale($sale->id));
        $this->assertSame([], $this->activityDatesFor($this->shiva));
    }

    #[Test]
    public function recording_the_same_sale_twice_does_not_duplicate_activity(): void
    {
        $sale = $this->sellOn($this->a, '2026-08-20');

        $this->recorder->recordSale($sale->id);
        $this->recorder->recordSale($sale->id);
        $this->recorder->recordSale($sale->id);

        $this->assertSame(1, MemberStatusActivity::query()->where('member_id', $this->a->id)->count());
        $this->assertSame(1, MemberStatusActivity::query()->where('member_id', $this->shiva->id)->count());
    }
}

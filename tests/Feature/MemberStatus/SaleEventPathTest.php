<?php

namespace Tests\Feature\MemberStatus;

use App\Models\Member;
use App\Models\RegistrySale;
use App\Modules\MemberStatus\Enums\CalculatedStatus;
use App\Modules\MemberStatus\Events\PropertySaleConfirmed;
use App\Modules\MemberStatus\Jobs\RecalculateMemberStatusJob;
use App\Modules\MemberStatus\Models\MemberStatusSnapshot;
use App\Modules\MemberStatus\Services\SaleActivityRecorder;
use App\Modules\MemberStatus\Services\StatusRecalculationService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * The event path (spec §24): a confirmed sale updates two statuses at once,
 * without waiting for the nightly command.
 *
 * The existing sale controller does NOT dispatch this event — that is the one
 * line of integration described in MEMBER_STATUS_INTEGRATION.md §7. These tests
 * dispatch it directly, which is exactly what that line would do.
 */
class SaleEventPathTest extends MemberStatusTestCase
{
    private Member $sponsor;

    private Member $seller;

    private Member $grandparent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->grandparent = Member::factory()->root()->create(['joining_date' => '2026-01-01']);
        $this->sponsor = Member::factory()->sponsoredBy($this->grandparent)->create(['joining_date' => '2026-01-01']);
        $this->seller = Member::factory()->sponsoredBy($this->sponsor)->create(['joining_date' => '2026-01-01']);
    }

    private function sale(string $date = '2026-08-20'): RegistrySale
    {
        return RegistrySale::factory()->withoutDetails()->forMember($this->seller)
            ->create(['registry_date' => $date, 'sale_date' => $date]);
    }

    #[Test]
    public function a_confirmed_sale_updates_the_seller_and_their_sponsor_only(): void
    {
        $sale = $this->sale();

        event(PropertySaleConfirmed::make($sale->id, $sale->member_id, $sale->registry_date));

        $recalculation = app(StatusRecalculationService::class);

        $this->assertSame(CalculatedStatus::Active, $recalculation->currentStatus($this->seller->id));
        $this->assertSame(CalculatedStatus::Active, $recalculation->currentStatus($this->sponsor->id));

        $this->assertNull(
            $recalculation->currentStatus($this->grandparent->id),
            'A sale must not reach past the seller\'s direct sponsor.'
        );

        $this->assertSame(2, MemberStatusSnapshot::query()->count());
    }

    #[Test]
    public function an_event_for_a_sale_that_is_not_valid_records_nothing(): void
    {
        $sale = $this->sale();

        DB::table('registry_sales')->where('id', $sale->id)->update(['status' => 'cancelled']);

        event(PropertySaleConfirmed::make($sale->id, $sale->member_id, $sale->registry_date));

        $this->assertSame(0, MemberStatusSnapshot::query()->count());
    }

    #[Test]
    public function an_event_naming_a_sale_that_does_not_exist_is_ignored(): void
    {
        // The payload is never taken as proof that a sale happened (spec §30).
        event(PropertySaleConfirmed::make(999999, $this->seller->id, '2026-08-20'));

        $this->assertSame(0, MemberStatusSnapshot::query()->count());
    }

    #[Test]
    public function the_queued_job_does_the_same_work_off_the_request(): void
    {
        $sale = $this->sale();

        RecalculateMemberStatusJob::forSale($sale->id)->handle(
            app(SaleActivityRecorder::class),
            app(StatusRecalculationService::class),
        );

        $this->assertSame(2, MemberStatusSnapshot::query()->count());
    }
}

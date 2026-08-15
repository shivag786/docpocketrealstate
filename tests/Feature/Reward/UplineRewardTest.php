<?php

namespace Tests\Feature\Reward;

use App\Enums\RewardType;
use App\Models\Member;
use App\Models\RegistrySale;
use App\Models\RewardLedger;
use App\Models\UplineCalculation;
use App\Models\User;
use App\Services\UplineRewardService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/06_TESTING_AND_ACCEPTANCE.md — Upline.
 *
 * For seller monthly sales 1,500 the pool is 75,000:
 *   5 → 15,000 each   4 → 18,750 each   3 → 25,000 each
 *   2 → 37,500 each   1 → 75,000        0 → no calculation
 */
class UplineRewardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private UplineRewardService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->service = app(UplineRewardService::class);
    }

    /**
     * Build a seller with a chain of `$uplines` active sponsors above them.
     *
     * @return array{seller: Member, uplines: array<int, Member>}
     */
    private function chain(int $uplines): array
    {
        $members = [];
        $previous = null;

        // Build top-down so the last created is the seller.
        for ($i = 0; $i < $uplines; $i++) {
            $previous = $previous === null
                ? Member::factory()->create()
                : Member::factory()->sponsoredBy($previous)->create();
            $members[] = $previous;
        }

        $seller = $previous === null
            ? Member::factory()->create()
            : Member::factory()->sponsoredBy($previous)->create();

        // Nearest upline first.
        return ['seller' => $seller, 'uplines' => array_reverse($members)];
    }

    private function sell(Member $seller, string $sqft = '1500.00', string $period = '2026-06'): void
    {
        RegistrySale::factory()->forMember($seller)->sqft($sqft)->inPeriod($period)->create();
    }

    /**
     * @return array<int, array{int, string}>
     */
    public static function uplineCountCases(): array
    {
        return [
            [5, '15000.00'],
            [4, '18750.00'],
            [3, '25000.00'],
            [2, '37500.00'],
            [1, '75000.00'],
        ];
    }

    #[Test]
    #[DataProvider('uplineCountCases')]
    public function the_pool_is_divided_by_the_actual_eligible_count(int $count, string $expectedShare): void
    {
        ['seller' => $seller, 'uplines' => $uplines] = $this->chain($count);
        $this->sell($seller);

        $run = $this->service->calculate('2026-06', $this->admin);

        $this->assertSame($count, $run->records_created);

        foreach ($uplines as $upline) {
            $this->assertSame(
                $expectedShare,
                RewardLedger::where('member_id', $upline->id)->value('amount'),
                "Upline {$upline->member_code} should receive {$expectedShare}"
            );
        }

        // The whole pool is distributed, nothing withheld.
        $this->assertSame('75000.00', $run->total_amount);
    }

    #[Test]
    public function a_seller_with_no_sponsor_produces_no_upline_calculation(): void
    {
        ['seller' => $seller] = $this->chain(0);
        $this->sell($seller);

        $run = $this->service->calculate('2026-06', $this->admin);

        $this->assertSame(0, $run->records_created);
        $this->assertSame('0.00', $run->total_amount);
        $this->assertSame(0, RewardLedger::ofType(RewardType::Upline)->count());
        $this->assertSame(0, UplineCalculation::count());
    }

    #[Test]
    public function only_five_uplines_are_paid_however_deep_the_chain(): void
    {
        ['seller' => $seller, 'uplines' => $uplines] = $this->chain(9);
        $this->sell($seller);

        $run = $this->service->calculate('2026-06', $this->admin);

        $this->assertSame(5, $run->records_created);

        // The five nearest are paid...
        foreach (array_slice($uplines, 0, 5) as $paid) {
            $this->assertSame('15000.00', RewardLedger::where('member_id', $paid->id)->value('amount'));
        }

        // ...and everyone above them receives nothing.
        foreach (array_slice($uplines, 5) as $unpaid) {
            $this->assertSame(0, RewardLedger::where('member_id', $unpaid->id)->count());
        }
    }

    #[Test]
    public function an_inactive_upline_is_skipped_and_the_walk_continues(): void
    {
        // Client-confirmed compression: only active members count, and the walk
        // continues past an inactive one to find a replacement.
        ['seller' => $seller, 'uplines' => $uplines] = $this->chain(6);

        // Make the nearest upline inactive.
        $uplines[0]->update(['status' => \App\Enums\MemberStatus::Inactive]);

        $this->sell($seller);

        $run = $this->service->calculate('2026-06', $this->admin);

        // Still five eligible uplines, found by walking one level deeper.
        $this->assertSame(5, $run->records_created);
        $this->assertSame('75000.00', $run->total_amount);

        $this->assertSame(0, RewardLedger::where('member_id', $uplines[0]->id)->count());

        foreach (array_slice($uplines, 1, 5) as $paid) {
            $this->assertSame('15000.00', RewardLedger::where('member_id', $paid->id)->value('amount'));
        }
    }

    #[Test]
    public function the_divisor_drops_when_no_replacement_upline_exists(): void
    {
        // Three sponsors, the middle one inactive, nothing above them.
        ['seller' => $seller, 'uplines' => $uplines] = $this->chain(3);
        $uplines[1]->update(['status' => \App\Enums\MemberStatus::Inactive]);

        $this->sell($seller);

        $run = $this->service->calculate('2026-06', $this->admin);

        // 2 eligible → pool / 2
        $this->assertSame(2, $run->records_created);
        $this->assertSame('37500.00', RewardLedger::where('member_id', $uplines[0]->id)->value('amount'));
        $this->assertSame('37500.00', RewardLedger::where('member_id', $uplines[2]->id)->value('amount'));
        $this->assertSame(0, RewardLedger::where('member_id', $uplines[1]->id)->count());
    }

    #[Test]
    public function an_entirely_inactive_chain_produces_nothing(): void
    {
        ['seller' => $seller, 'uplines' => $uplines] = $this->chain(3);

        foreach ($uplines as $upline) {
            $upline->update(['status' => \App\Enums\MemberStatus::Inactive]);
        }

        $this->sell($seller);

        $run = $this->service->calculate('2026-06', $this->admin);

        $this->assertSame(0, $run->records_created);
        $this->assertSame(0, RewardLedger::ofType(RewardType::Upline)->count());
    }

    #[Test]
    public function the_pool_uses_the_sellers_whole_month_not_each_sale(): void
    {
        ['seller' => $seller, 'uplines' => $uplines] = $this->chain(2);

        // Three sales totalling 1,500 must behave exactly like one sale of 1,500.
        foreach (['500.00', '700.00', '300.00'] as $sqft) {
            $this->sell($seller, $sqft);
        }

        $run = $this->service->calculate('2026-06', $this->admin);

        // One distribution, not three.
        $this->assertSame(2, $run->records_created);
        $this->assertSame('37500.00', RewardLedger::where('member_id', $uplines[0]->id)->value('amount'));
        $this->assertSame('1500.00', $run->total_sqft);
    }

    #[Test]
    public function each_seller_is_distributed_independently(): void
    {
        ['seller' => $sellerA, 'uplines' => $uplinesA] = $this->chain(1);
        ['seller' => $sellerB, 'uplines' => $uplinesB] = $this->chain(3);

        $this->sell($sellerA, '1500.00');
        $this->sell($sellerB, '1500.00');

        $run = $this->service->calculate('2026-06', $this->admin);

        $this->assertSame(4, $run->records_created);
        $this->assertSame('75000.00', RewardLedger::where('member_id', $uplinesA[0]->id)->value('amount'));
        $this->assertSame('25000.00', RewardLedger::where('member_id', $uplinesB[0]->id)->value('amount'));
        $this->assertSame('150000.00', $run->total_amount);
    }

    #[Test]
    public function a_member_can_receive_from_several_sellers(): void
    {
        $top = Member::factory()->create();
        $sellerA = Member::factory()->sponsoredBy($top)->create();
        $sellerB = Member::factory()->sponsoredBy($top)->create();

        $this->sell($sellerA, '1000.00');
        $this->sell($sellerB, '500.00');

        $this->service->calculate('2026-06', $this->admin);

        // 1000×50 = 50,000 and 500×50 = 25,000, both sole upline.
        $this->assertSame(2, RewardLedger::where('member_id', $top->id)->count());
        $this->assertSame(
            '75000.00',
            number_format((float) RewardLedger::where('member_id', $top->id)->sum('amount'), 2, '.', '')
        );
    }

    #[Test]
    public function the_same_pair_can_pay_again_in_a_different_month(): void
    {
        // Regression: the ledger uniqueness key must include the period, or the
        // second month would collide with the first.
        ['seller' => $seller, 'uplines' => $uplines] = $this->chain(1);

        $this->sell($seller, '1000.00', '2026-05');
        $this->sell($seller, '2000.00', '2026-06');

        $may = $this->service->calculate('2026-05', $this->admin);
        $june = $this->service->calculate('2026-06', $this->admin);

        $this->assertSame('50000.00', $may->total_amount);
        $this->assertSame('100000.00', $june->total_amount);
        $this->assertSame(2, RewardLedger::where('member_id', $uplines[0]->id)->count());
    }

    #[Test]
    public function target_status_does_not_affect_the_upline_reward(): void
    {
        // A tiny sale, nowhere near any target threshold, still distributes.
        ['seller' => $seller, 'uplines' => $uplines] = $this->chain(2);
        $this->sell($seller, '10.00');

        $run = $this->service->calculate('2026-06', $this->admin);

        // 10 × 50 = 500, split two ways.
        $this->assertSame('250.00', RewardLedger::where('member_id', $uplines[0]->id)->value('amount'));
        $this->assertSame('500.00', $run->total_amount);
    }

    #[Test]
    public function the_engine_never_consults_a_target(): void
    {
        $source = file_get_contents(app_path('Services/UplineRewardService.php'));

        foreach (['TargetService', 'target_cycles', 'TeamSalesService'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                str_replace(['Target achievement', 'Target engine'], '', $source),
                "UplineRewardService must not depend on [{$forbidden}]."
            );
        }
    }

    #[Test]
    public function shares_are_rounded_off_and_the_residual_is_visible(): void
    {
        // 1,000 Sq.Ft. → pool 50,000 → /3 = 16,666.6667 → rounds to 16,666.67.
        ['seller' => $seller, 'uplines' => $uplines] = $this->chain(3);
        $this->sell($seller, '1000.00');

        $preview = $this->service->preview('2026-06');
        $run = $this->service->calculate('2026-06', $this->admin);

        foreach ($uplines as $upline) {
            $this->assertSame('16666.67', RewardLedger::where('member_id', $upline->id)->value('amount'));
        }

        // 3 × 16,666.67 = 50,000.01 — one paisa above the pool, surfaced not hidden.
        $this->assertSame('50000.01', $run->total_amount);
        $this->assertSame('50000.00', $preview['pool']);
        $this->assertSame('0.01', $preview['residual']);
    }

    #[Test]
    public function the_working_behind_every_share_is_recorded(): void
    {
        ['seller' => $seller, 'uplines' => $uplines] = $this->chain(2);
        $this->sell($seller);

        $run = $this->service->calculate('2026-06', $this->admin);

        $this->assertSame(2, UplineCalculation::count());

        $nearest = UplineCalculation::where('receiver_id', $uplines[0]->id)->first();

        $this->assertSame($seller->id, $nearest->seller_id);
        $this->assertSame(1, $nearest->upline_level);
        $this->assertSame(1, $nearest->chain_depth);
        $this->assertSame('1500.00', $nearest->seller_sqft);
        $this->assertSame('50.00', $nearest->pool_rate);
        $this->assertSame('75000.00', $nearest->pool_amount);
        $this->assertSame(2, $nearest->eligible_upline_count);
        $this->assertSame('37500.00', $nearest->receiver_amount);
        $this->assertSame($run->id, $nearest->calculation_run_id);
        $this->assertFalse($nearest->wasCompressed());
    }

    #[Test]
    public function compression_is_auditable(): void
    {
        ['seller' => $seller, 'uplines' => $uplines] = $this->chain(3);
        $uplines[0]->update(['status' => \App\Enums\MemberStatus::Inactive]);

        $this->sell($seller);
        $this->service->calculate('2026-06', $this->admin);

        $nearest = UplineCalculation::where('receiver_id', $uplines[1]->id)->first();

        // Eligible position 1, but physically 2 sponsor links above the seller.
        $this->assertSame(1, $nearest->upline_level);
        $this->assertSame(2, $nearest->chain_depth);
        $this->assertTrue($nearest->wasCompressed());
    }

    #[Test]
    public function sales_outside_the_period_are_excluded(): void
    {
        ['seller' => $seller, 'uplines' => $uplines] = $this->chain(1);

        $this->sell($seller, '1500.00', '2026-06');
        $this->sell($seller, '9000.00', '2026-05');

        $run = $this->service->calculate('2026-06', $this->admin);

        $this->assertSame('75000.00', $run->total_amount);
    }

    #[Test]
    public function upline_and_direct_rewards_coexist_without_interfering(): void
    {
        ['seller' => $seller, 'uplines' => $uplines] = $this->chain(1);
        $this->sell($seller);

        app(\App\Services\DirectRewardService::class)->calculate('2026-06', $this->admin);
        $this->service->calculate('2026-06', $this->admin);

        // Direct: seller gets 1500 × 40 = 60,000
        $this->assertSame(
            '60000.00',
            RewardLedger::ofType(RewardType::Direct)->where('member_id', $seller->id)->value('amount')
        );

        // Upline: sole upline gets 1500 × 50 = 75,000
        $this->assertSame(
            '75000.00',
            RewardLedger::ofType(RewardType::Upline)->where('member_id', $uplines[0]->id)->value('amount')
        );

        // The seller receives no upline share from their own sale.
        $this->assertSame(
            0,
            RewardLedger::ofType(RewardType::Upline)->where('member_id', $seller->id)->count()
        );
    }
}

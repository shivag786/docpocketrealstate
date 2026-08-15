<?php

namespace Tests\Feature\Reward;

use App\Enums\CalculationRunStatus;
use App\Enums\CalculationRunType;
use App\Enums\RewardType;
use App\Models\CalculationRun;
use App\Models\Member;
use App\Models\RegistrySale;
use App\Models\RewardLedger;
use App\Models\User;
use App\Services\DirectRewardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * docs/06_TESTING_AND_ACCEPTANCE.md — Direct:
 *   - 1,500 × 40 = 60,000
 *   - multiple sales are summed
 *   - target failure does not stop direct reward
 */
class DirectRewardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private DirectRewardService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->service = app(DirectRewardService::class);
    }

    #[Test]
    public function fifteen_hundred_sqft_earns_sixty_thousand(): void
    {
        $member = Member::factory()->create();

        RegistrySale::factory()->forMember($member)->sqft('1500.00')->inPeriod('2026-06')->create();

        $run = $this->service->calculate('2026-06', $this->admin);

        $this->assertSame('60000.00', $run->total_amount);
        $this->assertSame('1500.00', $run->total_sqft);
        $this->assertSame(1, $run->records_created);

        $entry = RewardLedger::first();
        $this->assertSame('1500.00', $entry->sqft);
        $this->assertSame('40.00', $entry->rate);
        $this->assertSame('60000.00', $entry->amount);
        $this->assertSame(RewardType::Direct, $entry->reward_type);
        $this->assertSame($member->id, $entry->member_id);
    }

    #[Test]
    public function multiple_sales_by_one_member_are_summed(): void
    {
        $member = Member::factory()->create();

        foreach (['1000.00', '500.00', '250.50'] as $sqft) {
            RegistrySale::factory()->forMember($member)->sqft($sqft)->inPeriod('2026-06')->create();
        }

        $run = $this->service->calculate('2026-06', $this->admin);

        // 1750.50 × 40 = 70,020.00
        $this->assertSame('1750.50', $run->total_sqft);
        $this->assertSame('70020.00', $run->total_amount);
        $this->assertSame(3, $run->records_created);

        $totals = $this->service->forMember($member);
        $this->assertSame('70020.00', number_format((float) $totals->first()->amount, 2, '.', ''));
    }

    #[Test]
    public function each_sale_produces_its_own_traceable_ledger_row(): void
    {
        $member = Member::factory()->create();

        $a = RegistrySale::factory()->forMember($member)->sqft('100.00')->inPeriod('2026-06')->create();
        $b = RegistrySale::factory()->forMember($member)->sqft('200.00')->inPeriod('2026-06')->create();

        $this->service->calculate('2026-06', $this->admin);

        $this->assertSame(2, RewardLedger::count());

        foreach ([$a, $b] as $sale) {
            $this->assertDatabaseHas('reward_ledger', [
                'source_type' => 'registry_sale',
                'source_id' => $sale->id,
                'reward_type' => RewardType::Direct->value,
            ]);
        }
    }

    #[Test]
    public function only_the_sellers_own_sales_count_not_their_downline(): void
    {
        // The Direct engine must never look at the network. Downline sales
        // belong to the Team Target engine (Phase 7).
        $sponsor = Member::factory()->create();
        $downline = Member::factory()->sponsoredBy($sponsor)->create();

        RegistrySale::factory()->forMember($sponsor)->sqft('1000.00')->inPeriod('2026-06')->create();
        RegistrySale::factory()->forMember($downline)->sqft('4000.00')->inPeriod('2026-06')->create();

        $this->service->calculate('2026-06', $this->admin);

        $sponsorAmount = RewardLedger::where('member_id', $sponsor->id)->sum('amount');
        $downlineAmount = RewardLedger::where('member_id', $downline->id)->sum('amount');

        $this->assertSame('40000.00', number_format((float) $sponsorAmount, 2, '.', ''));
        $this->assertSame('160000.00', number_format((float) $downlineAmount, 2, '.', ''));
    }

    #[Test]
    public function target_status_does_not_affect_the_direct_reward(): void
    {
        // A member far below every target threshold (5,000 / 10,000 / 35,000)
        // still receives the full direct reward on every approved sale.
        $member = Member::factory()->create();

        RegistrySale::factory()->forMember($member)->sqft('100.00')->inPeriod('2026-06')->create();

        $run = $this->service->calculate('2026-06', $this->admin);

        $this->assertSame('4000.00', $run->total_amount);
        $this->assertSame(1, RewardLedger::ofType(RewardType::Direct)->count());
    }

    #[Test]
    public function the_engine_never_consults_a_target(): void
    {
        // Structural guard: the Direct engine must stay independent of the
        // Target engine (docs/02_BUSINESS_RULES.md §8).
        $source = file_get_contents(app_path('Services/DirectRewardService.php'));

        foreach (['TargetService', 'target_cycles', 'TeamSalesService'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                str_replace('Target engine', '', $source),
                "DirectRewardService must not depend on [{$forbidden}]."
            );
        }
    }

    #[Test]
    public function sales_outside_the_period_are_excluded(): void
    {
        $member = Member::factory()->create();

        RegistrySale::factory()->forMember($member)->sqft('1000.00')->inPeriod('2026-06')->create();
        RegistrySale::factory()->forMember($member)->sqft('9000.00')->inPeriod('2026-05')->create();

        $run = $this->service->calculate('2026-06', $this->admin);

        $this->assertSame('1000.00', $run->total_sqft);
        $this->assertSame('40000.00', $run->total_amount);
    }

    #[Test]
    public function the_period_follows_the_registry_date_not_the_sale_date(): void
    {
        $member = Member::factory()->create();

        RegistrySale::factory()->forMember($member)->sqft('1500.00')->create([
            'registry_date' => '2026-06-10',
            'sale_date' => '2026-05-02',
        ]);

        $this->assertSame('60000.00', $this->service->calculate('2026-06', $this->admin)->total_amount);
        $this->assertSame('0.00', $this->service->calculate('2026-05', $this->admin)->total_amount);
    }

    #[Test]
    public function a_period_with_no_sales_produces_an_empty_completed_run(): void
    {
        $run = $this->service->calculate('2026-06', $this->admin);

        $this->assertSame(CalculationRunStatus::Completed, $run->status);
        $this->assertSame(0, $run->records_created);
        $this->assertSame('0.00', $run->total_amount);
        $this->assertSame(0, RewardLedger::count());
    }

    #[Test]
    public function the_rate_is_frozen_onto_each_ledger_row(): void
    {
        $member = Member::factory()->create();
        RegistrySale::factory()->forMember($member)->sqft('1000.00')->inPeriod('2026-06')->create();

        $this->service->calculate('2026-06', $this->admin);

        $this->assertSame('40.00', RewardLedger::first()->rate);

        // Changing the configured rate must not alter an existing ledger row.
        config(['rewards.rates.direct' => 99]);

        $this->assertSame('40.00', RewardLedger::first()->fresh()->rate);
        $this->assertSame('40000.00', RewardLedger::first()->fresh()->amount);
    }

    #[Test]
    public function every_entry_is_traceable_to_its_run(): void
    {
        $member = Member::factory()->create();
        RegistrySale::factory()->forMember($member)->sqft('1000.00')->inPeriod('2026-06')->create();

        $run = $this->service->calculate('2026-06', $this->admin);

        $this->assertSame($run->id, RewardLedger::first()->calculation_run_id);
        $this->assertSame($this->admin->id, $run->initiated_by);
        $this->assertSame(1, $run->entries()->count());
    }

    #[Test]
    public function decimal_sqft_values_stay_exact(): void
    {
        $member = Member::factory()->create();

        foreach (['0.01', '1234.56', '999.99'] as $sqft) {
            RegistrySale::factory()->forMember($member)->sqft($sqft)->inPeriod('2026-06')->create();
        }

        $run = $this->service->calculate('2026-06', $this->admin);

        // 0.01 + 1234.56 + 999.99 = 2234.56 → × 40 = 89,382.40
        $this->assertSame('2234.56', $run->total_sqft);
        $this->assertSame('89382.40', $run->total_amount);
    }
}

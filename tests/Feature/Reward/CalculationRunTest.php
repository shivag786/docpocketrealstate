<?php

namespace Tests\Feature\Reward;

use App\Enums\CalculationRunStatus;
use App\Enums\CalculationRunType;
use App\Models\CalculationRun;
use App\Models\Member;
use App\Models\RegistrySale;
use App\Models\RewardLedger;
use App\Models\User;
use App\Services\CalculationRunService;
use App\Services\DirectRewardService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * docs/05_CALCULATION_ENGINE_SPEC.md §G — duplicate protection, and
 * docs/06_TESTING_AND_ACCEPTANCE.md — "First run succeeds. Identical second run
 * cannot duplicate. Failed transaction rolls back. Ledger reconciles."
 */
class CalculationRunTest extends TestCase
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

    private function seedSale(string $period = '2026-06', string $sqft = '1000.00'): RegistrySale
    {
        return RegistrySale::factory()
            ->forMember(Member::factory()->create())
            ->sqft($sqft)
            ->inPeriod($period)
            ->create();
    }

    #[Test]
    public function the_first_run_succeeds(): void
    {
        $this->seedSale();

        $run = $this->service->calculate('2026-06', $this->admin);

        $this->assertSame(CalculationRunStatus::Completed, $run->status);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->completed_at);
        $this->assertSame(1, RewardLedger::count());
    }

    #[Test]
    public function an_identical_second_run_is_refused(): void
    {
        $this->seedSale();

        $this->service->calculate('2026-06', $this->admin);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already been calculated');

        $this->service->calculate('2026-06', $this->admin);
    }

    #[Test]
    public function a_refused_second_run_creates_no_extra_ledger_rows(): void
    {
        $this->seedSale();

        $this->service->calculate('2026-06', $this->admin);

        try {
            $this->service->calculate('2026-06', $this->admin);
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(1, RewardLedger::count());
        $this->assertSame(1, CalculationRun::completed()->count());
    }

    #[Test]
    public function the_database_refuses_a_duplicate_reward_for_the_same_source(): void
    {
        // The last line of defence: even if the run guard were bypassed, the
        // unique index makes double-payment impossible.
        $sale = $this->seedSale();
        $run = $this->service->calculate('2026-06', $this->admin);

        $existing = RewardLedger::first();

        $this->expectException(QueryException::class);

        RewardLedger::insert([
            'member_id' => $existing->member_id,
            'reward_type' => $existing->reward_type->value,
            'source_type' => 'registry_sale',
            'source_id' => $sale->id,
            'period' => '2026-06',
            'sqft' => '1000.00',
            'rate' => '40.00',
            'amount' => '40000.00',
            'status' => 'posted',
            'calculation_run_id' => $run->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function different_periods_can_each_be_calculated(): void
    {
        $this->seedSale('2026-05', '500.00');
        $this->seedSale('2026-06', '1000.00');

        $may = $this->service->calculate('2026-05', $this->admin);
        $june = $this->service->calculate('2026-06', $this->admin);

        $this->assertSame('20000.00', $may->total_amount);
        $this->assertSame('40000.00', $june->total_amount);
        $this->assertSame(2, RewardLedger::count());
    }

    #[Test]
    public function a_failed_run_rolls_back_the_whole_transaction(): void
    {
        $this->seedSale();

        $runs = app(CalculationRunService::class);

        try {
            $runs->execute(
                '2026-06',
                CalculationRunType::Direct,
                $this->admin,
                function (CalculationRun $run) {
                    RewardLedger::insert([
                        'member_id' => Member::first()->id,
                        'reward_type' => 'direct',
                        'source_type' => 'registry_sale',
                        'source_id' => 999,
                        'period' => '2026-06',
                        'sqft' => '1.00',
                        'rate' => '40.00',
                        'amount' => '40.00',
                        'status' => 'posted',
                        'calculation_run_id' => $run->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    throw new RuntimeException('engine exploded');
                }
            );
            $this->fail('Expected the run to throw.');
        } catch (RuntimeException $e) {
            $this->assertSame('engine exploded', $e->getMessage());
        }

        // No ledger rows survive, and no completed run exists.
        $this->assertSame(0, RewardLedger::count());
        $this->assertSame(0, CalculationRun::completed()->count());
    }

    #[Test]
    public function a_failure_is_recorded_so_the_attempt_stays_visible(): void
    {
        $runs = app(CalculationRunService::class);

        try {
            $runs->execute('2026-06', CalculationRunType::Direct, $this->admin,
                fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
            // expected
        }

        $failed = CalculationRun::where('status', CalculationRunStatus::Failed)->first();

        $this->assertNotNull($failed);
        $this->assertSame('boom', $failed->error_message);
        $this->assertSame($this->admin->id, $failed->initiated_by);
    }

    #[Test]
    public function a_failed_run_does_not_block_a_later_successful_one(): void
    {
        $this->seedSale();

        $runs = app(CalculationRunService::class);

        try {
            $runs->execute('2026-06', CalculationRunType::Direct, $this->admin,
                fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
            // expected
        }

        $run = $this->service->calculate('2026-06', $this->admin);

        $this->assertSame(CalculationRunStatus::Completed, $run->status);
        $this->assertSame('40000.00', $run->total_amount);
    }

    #[Test]
    public function the_run_totals_reconcile_with_the_ledger(): void
    {
        foreach (['100.00', '250.50', '1000.00'] as $sqft) {
            $this->seedSale('2026-06', $sqft);
        }

        $run = $this->service->calculate('2026-06', $this->admin);

        $this->assertSame(
            number_format((float) RewardLedger::sum('amount'), 2, '.', ''),
            $run->total_amount
        );
        $this->assertSame(
            number_format((float) RewardLedger::sum('sqft'), 2, '.', ''),
            $run->total_sqft
        );
        $this->assertSame(RewardLedger::count(), $run->records_created);
    }

    #[Test]
    public function an_invalid_period_is_rejected(): void
    {
        foreach (['2026-13', '2026', 'June', '2026-6', ''] as $period) {
            try {
                $this->service->calculate($period, $this->admin);
                $this->fail("Period [{$period}] should have been rejected.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Invalid period', $e->getMessage());
            }
        }
    }

    #[Test]
    public function a_future_period_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('in the future');

        $this->service->calculate(now()->addMonth()->format('Y-m'), $this->admin);
    }

    #[Test]
    public function the_current_month_is_allowed(): void
    {
        $run = $this->service->calculate(now()->format('Y-m'), $this->admin);

        $this->assertSame(CalculationRunStatus::Completed, $run->status);
    }
}

<?php

namespace Tests\Feature\Reward;

use App\Enums\CalculationRunStatus;
use App\Enums\LedgerStatus;
use App\Enums\RewardType;
use App\Models\CalculationRun;
use App\Models\Member;
use App\Models\RegistrySale;
use App\Models\RewardLedger;
use App\Models\User;
use App\Services\DirectRewardService;
use App\Services\RewardLedgerService;
use App\Services\UplineRewardService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 13 — reconciliation.
 *
 * Every check is tested twice: that it passes on a healthy month, and that it
 * actually catches the fault it claims to catch. A check only ever verified in
 * its passing state is decoration.
 *
 * The faults are injected with raw UPDATE statements on purpose. Going through
 * the engines could not produce them — which is the point — so the only way to
 * prove the checks work is to break the data underneath them.
 */
class LedgerReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private RewardLedgerService $ledger;

    private string $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->ledger = app(RewardLedgerService::class);
        $this->period = now()->subMonths(2)->format('Y-m');
    }

    /*
    |--------------------------------------------------------------------------
    | The healthy month
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_month_produced_by_the_engines_reconciles_cleanly(): void
    {
        $this->healthyMonth();

        $report = $this->ledger->reconcile($this->period);

        $this->assertTrue($report['clean'], $this->explainFailures($report));
        $this->assertSame(0, $report['failed']);
        $this->assertFalse($report['empty']);
    }

    #[Test]
    public function a_month_nobody_calculated_is_reported_as_empty_rather_than_broken(): void
    {
        $report = $this->ledger->reconcile($this->period);

        $this->assertTrue($report['empty']);
        $this->assertTrue($report['clean']);

        // And the Direct comparison says why it has nothing to say, instead of
        // reporting a shortfall against sales that were never calculated.
        $this->assertSame('skipped', $this->check($report, 'direct_sales')['status']);
    }

    #[Test]
    public function the_reconciliation_page_states_the_verdict(): void
    {
        $this->healthyMonth();

        $this->actingAs($this->admin)
            ->get(route('admin.ledger.reconciliation', ['period' => $this->period]))
            ->assertOk()
            ->assertSee($this->period.' reconciles.')
            ->assertSee('Engine by engine')
            // All eight checks are named on the page.
            ->assertSee('Every amount belongs to a completed run of its own month')
            ->assertSee('Every amount still points at the record it was calculated from')
            ->assertSee('Direct and Target amounts multiply out exactly')
            ->assertSee('Every pool was shared out in full')
            ->assertSee('No member was paid twice from the same source')
            // Escaped, not raw: Blade renders the apostrophe as &#039;.
            ->assertSee('Each engine\'s ledger total matches the total its run recorded')
            ->assertSee('The Direct ledger equals the month\'s approved sales')
            ->assertSee('Every confirmed payment names an admin and a date');
    }

    /*
    |--------------------------------------------------------------------------
    | Check 1 — run ownership
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_reward_left_on_a_superseded_run_is_caught(): void
    {
        $this->healthyMonth();

        $orphan = CalculationRun::create([
            'period' => $this->period,
            'run_type' => 'direct',
            'status' => CalculationRunStatus::Superseded,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        DB::table('reward_ledger')
            ->where('reward_type', RewardType::Direct->value)
            ->limit(1)
            ->update(['calculation_run_id' => $orphan->id]);

        $check = $this->check($this->ledger->reconcile($this->period), 'run_ownership');

        $this->assertSame('failed', $check['status']);
        $this->assertStringContainsString('the run is superseded', $check['offenders'][0]);
    }

    #[Test]
    public function a_reward_written_by_the_wrong_engine_is_caught(): void
    {
        $this->healthyMonth();

        $uplineRun = CalculationRun::query()
            ->where('period', $this->period)
            ->where('run_type', 'upline')
            ->firstOrFail();

        // A direct reward pointing at the upline run: the amount exists but
        // nothing that produced it does.
        DB::table('reward_ledger')
            ->where('reward_type', RewardType::Direct->value)
            ->limit(1)
            ->update(['calculation_run_id' => $uplineRun->id]);

        $check = $this->check($this->ledger->reconcile($this->period), 'run_ownership');

        $this->assertSame('failed', $check['status']);
        $this->assertStringContainsString('the run is a upline run', $check['offenders'][0]);
    }

    /*
    |--------------------------------------------------------------------------
    | Check 2 — sources
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_reward_whose_source_sale_has_vanished_is_caught(): void
    {
        $this->healthyMonth();

        $sale = RegistrySale::query()->firstOrFail();
        DB::table('registry_sales')->where('id', $sale->id)->delete();

        $check = $this->check($this->ledger->reconcile($this->period), 'sources');

        $this->assertSame('failed', $check['status']);
        $this->assertStringContainsString('registry_sale #'.$sale->id, $check['offenders'][0]);
    }

    #[Test]
    public function the_company_club_pool_is_never_reported_as_a_missing_source(): void
    {
        // Its source is the MONTH, stored as source_id 0 deliberately, because a
        // foreign key to any one sale would be a lie. It must not be mistaken
        // for a dangling reference.
        $this->healthyMonth();

        $member = Member::factory()->create();
        $run = CalculationRun::create([
            'period' => $this->period,
            'run_type' => 'company_club',
            'status' => CalculationRunStatus::Completed,
            'started_at' => now(),
            'completed_at' => now(),
            'records_created' => 1,
            'total_sqft' => '1000.00',
            'total_amount' => '50000.00',
        ]);

        RewardLedger::create([
            'member_id' => $member->id,
            'reward_type' => RewardType::CompanyClub,
            'source_type' => 'company_club_pool',
            'source_id' => 0,
            'period' => $this->period,
            'sqft' => '1000.00',
            'rate' => '50.00',
            'amount' => '50000.00',
            'status' => LedgerStatus::Posted,
            'calculation_run_id' => $run->id,
        ]);

        $report = $this->ledger->reconcile($this->period);

        $this->assertSame('passed', $this->check($report, 'sources')['status']);
        $this->assertSame('passed', $this->check($report, 'pools')['status']);
    }

    /*
    |--------------------------------------------------------------------------
    | Check 3 — row arithmetic
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_direct_amount_that_does_not_multiply_out_is_caught(): void
    {
        $this->healthyMonth();

        DB::table('reward_ledger')
            ->where('reward_type', RewardType::Direct->value)
            ->limit(1)
            ->update(['amount' => '1.00']);

        $check = $this->check($this->ledger->reconcile($this->period), 'arithmetic');

        $this->assertSame('failed', $check['status']);
        $this->assertStringContainsString('but the row says ₹1.00', $check['offenders'][0]);
    }

    #[Test]
    public function an_upline_row_is_never_asked_to_multiply_out(): void
    {
        // 1,000 Sq.Ft. × ₹50 = ₹50,000 on the row, but the share paid is that
        // pool divided by the eligible uplines. A check that demanded the
        // multiplication would fail on every healthy month.
        //
        // Two uplines, deliberately: with one the share IS the whole pool and
        // the row would multiply out by coincidence, proving nothing.
        $sponsors = $this->chainOf(2);
        $seller = Member::factory()->sponsoredBy($sponsors[1])->create();

        RegistrySale::factory()->forMember($seller)->sqft('1000.00')
            ->inPeriod($this->period)->create();

        app(DirectRewardService::class)->calculate($this->period, $this->admin);
        app(UplineRewardService::class)->calculate($this->period, $this->admin);

        $upline = RewardLedger::query()->ofType(RewardType::Upline)->firstOrFail();

        $this->assertNotSame(
            bcmul((string) $upline->sqft, (string) $upline->rate, 2),
            (string) $upline->amount,
            'The fixture must actually contain a row that does not multiply out.',
        );

        $this->assertSame(
            'passed',
            $this->check($this->ledger->reconcile($this->period), 'arithmetic')['status'],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Check 4 — pools
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_upline_pool_that_was_not_fully_shared_out_is_caught(): void
    {
        $this->healthyMonth();

        DB::table('reward_ledger')
            ->where('reward_type', RewardType::Upline->value)
            ->limit(1)
            ->update(['amount' => '1.00']);

        $check = $this->check($this->ledger->reconcile($this->period), 'pools');

        $this->assertSame('failed', $check['status']);
        $this->assertStringContainsString('Upline pool for seller', $check['offenders'][0]);
    }

    #[Test]
    public function a_few_paise_of_rounding_residual_is_not_reported_as_a_fault(): void
    {
        // Shares are rounded independently, so they need not re-sum to the pool.
        // ₹50,000 / 3 = 3 × ₹16,666.67 = ₹50,000.01. Reporting that as a
        // shortfall would fail on any three-upline chain.
        $sponsors = $this->chainOf(3);
        $seller = Member::factory()->sponsoredBy($sponsors[2])->create();

        RegistrySale::factory()->forMember($seller)->sqft('1000.00')
            ->inPeriod($this->period)->create();

        app(DirectRewardService::class)->calculate($this->period, $this->admin);
        app(UplineRewardService::class)->calculate($this->period, $this->admin);

        $shares = RewardLedger::query()->ofType(RewardType::Upline)->pluck('amount');

        $this->assertCount(3, $shares);
        $this->assertSame('50000.01', array_reduce(
            $shares->all(),
            fn (string $carry, $share) => bcadd($carry, (string) $share, 2),
            '0.00',
        ), 'The fixture must actually produce the one-paisa residual.');

        $report = $this->ledger->reconcile($this->period);

        $this->assertSame('passed', $this->check($report, 'pools')['status']);
        $this->assertTrue($report['clean'], $this->explainFailures($report));
    }

    /*
    |--------------------------------------------------------------------------
    | Check 5 — duplicates
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_database_refuses_a_duplicate_reward_and_the_check_agrees(): void
    {
        $this->healthyMonth();

        $existing = RewardLedger::query()->ofType(RewardType::Direct)->firstOrFail();

        // The unique index makes the failing case unconstructable through any
        // route, which is why the check can only be verified in its passing
        // state here — so the index itself is asserted alongside it.
        try {
            DB::table('reward_ledger')->insert([
                'member_id' => $existing->member_id,
                'reward_type' => $existing->reward_type->value,
                'source_type' => $existing->source_type,
                'source_id' => $existing->source_id,
                'period' => $existing->period,
                'sqft' => $existing->sqft,
                'rate' => $existing->rate,
                'amount' => $existing->amount,
                'status' => LedgerStatus::Posted->value,
                'calculation_run_id' => $existing->calculation_run_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->fail('The ledger must refuse a second reward from the same source.');
        } catch (QueryException) {
            // Expected: reward_ledger_source_unique.
        }

        $this->assertSame(
            'passed',
            $this->check($this->ledger->reconcile($this->period), 'duplicates')['status'],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Check 6 — run totals
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_run_that_disagrees_with_its_own_rows_is_caught(): void
    {
        $this->healthyMonth();

        CalculationRun::query()
            ->where('period', $this->period)
            ->where('run_type', 'direct')
            ->update(['total_amount' => '999.00']);

        $check = $this->check($this->ledger->reconcile($this->period), 'run_totals');

        $this->assertSame('failed', $check['status']);
        $this->assertStringContainsString('Direct Sale', $check['offenders'][0]);
    }

    /*
    |--------------------------------------------------------------------------
    | Check 7 — Direct against the sales
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_direct_ledger_behind_its_sales_is_caught(): void
    {
        [$seller] = $this->healthyMonth();

        // A sale entered without a rebuild — which is what a locked month or a
        // failed recalculation leaves behind.
        RegistrySale::factory()->forMember($seller)->sqft('500.00')
            ->inPeriod($this->period)->create();

        $check = $this->check($this->ledger->reconcile($this->period), 'direct_sales');

        $this->assertSame('failed', $check['status']);
        $this->assertStringContainsString('rebuild it from the calculation center', strtolower($check['message']));
    }

    #[Test]
    public function the_other_three_engines_are_never_compared_against_raw_sales(): void
    {
        // The Calculation Center already had to remove exactly this false alarm
        // once, on live data. Upline divides through the network, Target pays a
        // threshold, and the Company Club excludes inactive sellers — none of
        // them equals Sq.Ft. × their rate, and a check that expected it would
        // condemn every healthy month.
        $this->healthyMonth();

        $check = $this->check($this->ledger->reconcile($this->period), 'direct_sales');

        $this->assertSame('passed', $check['status']);
        $this->assertStringNotContainsString('Upline', $check['message']);
        $this->assertStringContainsString('only engine', $check['explains']);
    }

    /*
    |--------------------------------------------------------------------------
    | Check 8 — payment evidence
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_payment_with_no_admin_behind_it_is_caught(): void
    {
        $this->healthyMonth();

        DB::table('reward_ledger')
            ->where('reward_type', RewardType::Direct->value)
            ->limit(1)
            ->update(['status' => LedgerStatus::Paid->value, 'paid_at' => now(), 'paid_by' => null]);

        $check = $this->check($this->ledger->reconcile($this->period), 'payment_evidence');

        $this->assertSame('failed', $check['status']);
        $this->assertStringContainsString('records no admin', $check['offenders'][0]);
    }

    #[Test]
    public function a_payment_made_through_the_screen_is_always_attributed(): void
    {
        $this->healthyMonth();

        $reward = RewardLedger::query()->ofType(RewardType::Direct)->firstOrFail();

        $this->actingAs($this->admin)->post(route('admin.ledger.paid', $reward->id));

        $this->assertSame(
            'passed',
            $this->check($this->ledger->reconcile($this->period), 'payment_evidence')['status'],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Reconciliation never writes
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function reconciling_a_broken_month_repairs_nothing(): void
    {
        // A report that could fix what it measures could hide a fault by fixing
        // it, and the operator would never learn the month had been wrong.
        $this->healthyMonth();

        DB::table('reward_ledger')
            ->where('reward_type', RewardType::Direct->value)
            ->limit(1)
            ->update(['amount' => '1.00']);

        $before = DB::table('reward_ledger')->orderBy('id')->get();
        $runsBefore = DB::table('calculation_runs')->orderBy('id')->get();

        $this->ledger->reconcile($this->period);
        $this->actingAs($this->admin)
            ->get(route('admin.ledger.reconciliation', ['period' => $this->period]))
            ->assertOk();

        $this->assertEquals($before, DB::table('reward_ledger')->orderBy('id')->get());
        $this->assertEquals($runsBefore, DB::table('calculation_runs')->orderBy('id')->get());
    }

    #[Test]
    public function the_service_has_no_write_path_at_all(): void
    {
        // Structural, not behavioural: the guarantee above holds only while
        // nothing in here can save, update, insert or delete.
        $source = file_get_contents(app_path('Services/RewardLedgerService.php'));

        foreach (['->save(', '->update(', '->insert(', '->delete(', 'DB::transaction'] as $writer) {
            $this->assertStringNotContainsString(
                $writer,
                $source,
                "RewardLedgerService must never write. Found [{$writer}].",
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * A sponsor above a seller, one sale, Direct and Upline calculated.
     *
     * @return array{0: Member, 1: Member} seller, sponsor
     */
    private function healthyMonth(): array
    {
        $sponsor = Member::factory()->create();
        $seller = Member::factory()->sponsoredBy($sponsor)->create();

        RegistrySale::factory()->forMember($seller)->sqft('1000.00')
            ->inPeriod($this->period)->create();

        app(DirectRewardService::class)->calculate($this->period, $this->admin);
        app(UplineRewardService::class)->calculate($this->period, $this->admin);

        return [$seller, $sponsor];
    }

    /**
     * A chain of sponsors, root first.
     *
     * @return array<int, Member>
     */
    private function chainOf(int $length): array
    {
        $chain = [];
        $parent = null;

        for ($i = 0; $i < $length; $i++) {
            $chain[] = $parent = $parent === null
                ? Member::factory()->create()
                : Member::factory()->sponsoredBy($parent)->create();
        }

        return $chain;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function check(array $report, string $key): array
    {
        foreach ($report['checks'] as $check) {
            if ($check['key'] === $key) {
                return $check;
            }
        }

        $this->fail("Reconciliation has no check named [{$key}].");
    }

    /** @param  array<string, mixed>  $report */
    private function explainFailures(array $report): string
    {
        $failures = array_filter($report['checks'], fn (array $c) => $c['status'] === 'failed');

        return 'Failing checks: '.json_encode(array_map(
            fn (array $c) => [$c['key'], $c['message'], $c['offenders']],
            array_values($failures),
        ), JSON_UNESCAPED_UNICODE);
    }
}

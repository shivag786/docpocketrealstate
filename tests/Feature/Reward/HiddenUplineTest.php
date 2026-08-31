<?php

namespace Tests\Feature\Reward;

use App\Enums\RewardType;
use App\Models\CalculationRun;
use App\Models\Member;
use App\Models\RegistrySale;
use App\Models\RewardLedger;
use App\Models\User;
use App\Services\PeriodRecalculationService;
use App\Services\RewardLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "no need of upline any where. hide from their" — client, 2026-08-27.
 *
 * Confirmed the same day to mean HIDE THE SCREENS, NOT STOP PAYING. This file
 * holds both halves of that, because each is worthless without the other:
 *
 *  - the word "Upline" appears on no operator screen; and
 *  - the engine still runs on every sale and still writes ₹50 per Sq.Ft.
 *
 * The sweep below is deliberately blunt — it loads the real pages and looks for
 * the word. A per-page assertion would pass while some panel nobody thought of
 * kept showing it.
 *
 * TWO THINGS ARE EXEMPT, BOTH ON PURPOSE:
 *
 *  1. **Reconciliation.** It is the audit screen, and a reward that is still
 *     being written must still be checked or the arrangement becomes money
 *     moving where nothing is watching. It shows the engine under a "Hidden"
 *     badge and says why.
 *  2. **The sponsor chain and the Company Club walk.** "Upline" there means the
 *     chain of sponsors, not this reward. Both were confirmed untouched. The
 *     member profile's tab was renamed to "Sponsor Chain" so the word is gone
 *     from view, but the feature is exactly as it was.
 */
class HiddenUplineTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->period = now()->subMonth()->format('Y-m');
    }

    #[Test]
    public function the_word_upline_appears_on_no_operator_screen(): void
    {
        [$seller, $sponsor] = $this->networkWithRewards();

        $screens = [
            'dashboard' => route('admin.dashboard'),
            'reward ledger' => route('admin.ledger.index', ['period' => $this->period]),
            'member statement' => route('admin.ledger.member', $sponsor),
            'calculation center' => route('admin.calculations.index', ['period' => $this->period]),
            'member profile' => route('admin.members.show', $sponsor),
            'member edit' => route('admin.members.edit', $sponsor),
            'sale detail' => route('admin.sales.show', RegistrySale::query()->firstOrFail()),
            'sale entry' => route('admin.sales.create'),
            'sales history' => route('admin.sales.index'),
            'direct sale report' => route('admin.rewards.direct-sales', ['range' => 'all']),
            'team sales report' => route('admin.rewards.team-sales', ['period' => $this->period]),
            'team contributors' => route('admin.rewards.team-sales.contributors', $sponsor),
            'targets' => route('admin.targets.achieved', ['period' => $this->period]),
            'target detail' => route('admin.targets.show', $seller),
            'sponsor tree' => route('admin.tree.index'),
        ];

        foreach ($screens as $name => $url) {
            $response = $this->actingAs($this->admin)->get($url);

            $response->assertOk();

            $this->assertStringNotContainsStringIgnoringCase(
                'upline',
                $this->body($response->getContent()),
                "The {$name} screen still says \"upline\".",
            );
        }
    }

    #[Test]
    public function the_engine_still_runs_and_still_pays(): void
    {
        // THE LOAD-BEARING TEST. Hiding was confirmed to change the screens and
        // nothing else. If this fails, uplines have silently stopped being paid
        // and no screen would show it.
        $this->networkWithRewards();

        $shares = RewardLedger::query()->ofType(RewardType::Upline)->get();

        $this->assertCount(1, $shares, 'The hidden engine must still write to the ledger.');
        // 1,000 Sq.Ft. × ₹50, one eligible upline, so the whole pool.
        $this->assertSame('50000.00', (string) $shares->first()->amount);

        $this->assertTrue(
            CalculationRun::query()
                ->where('period', $this->period)
                ->where('run_type', 'upline')
                ->completed()
                ->exists(),
            'A hidden engine must still produce a completed run.',
        );
    }

    #[Test]
    public function a_new_sale_still_rebuilds_the_hidden_engine(): void
    {
        [$seller] = $this->networkWithRewards();

        RegistrySale::factory()->forMember($seller)->sqft('500.00')
            ->inPeriod($this->period)->create();

        app(PeriodRecalculationService::class)->recalculate($this->period, $this->admin);

        // 1,500 Sq.Ft. × ₹50 to the one eligible upline.
        $this->assertSame(
            '75000.00',
            (string) RewardLedger::query()->ofType(RewardType::Upline)->firstOrFail()->amount,
        );
    }

    #[Test]
    public function reconciliation_is_the_one_screen_that_still_shows_it(): void
    {
        $this->networkWithRewards();

        $this->actingAs($this->admin)
            ->get(route('admin.ledger.reconciliation', ['period' => $this->period]))
            ->assertOk()
            ->assertSee('Upline')
            ->assertSee('Hidden')
            // And it accounts for the money, rather than merely naming it.
            ->assertSee('₹50,000.00')
            ->assertSee('calculated on every sale');
    }

    #[Test]
    public function reconciliation_still_checks_the_hidden_engine(): void
    {
        // Showing a hidden engine would be pointless if its rows were exempt
        // from the checks. Break one and the report must still catch it.
        $this->networkWithRewards();

        DB::table('reward_ledger')
            ->where('reward_type', RewardType::Upline->value)
            ->update(['amount' => '1.00']);

        $report = app(RewardLedgerService::class)->reconcile($this->period);

        $this->assertFalse($report['clean']);

        $pools = collect($report['checks'])->firstWhere('key', 'pools');
        $this->assertSame('failed', $pools['status']);
        $this->assertStringContainsString('Upline pool for seller', $pools['offenders'][0]);
    }

    #[Test]
    public function one_config_line_brings_every_screen_back(): void
    {
        // The reason this is a flag and not a deletion. If the client changes
        // their mind, the screens return with their figures intact.
        config(['rewards.visibility.upline' => true]);

        [, $sponsor] = $this->networkWithRewards();

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Upline Reward');

        $this->actingAs($this->admin)
            ->get(route('admin.rewards.upline', ['period' => $this->period]))
            ->assertOk();

        $this->actingAs($this->admin)
            ->get(route('admin.ledger.index', ['period' => $this->period]))
            ->assertOk()
            ->assertSee('₹50,000.00');

        $this->actingAs($this->admin)
            ->get(route('admin.members.show', $sponsor))
            ->assertOk()
            ->assertSee('Upline Reward');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * A sponsor above a seller, one sale, every engine rebuilt.
     *
     * @return array{0: Member, 1: Member} seller, sponsor
     */
    private function networkWithRewards(): array
    {
        $sponsor = Member::factory()->create();
        $seller = Member::factory()->sponsoredBy($sponsor)->create();

        RegistrySale::factory()->forMember($seller)->sqft('1000.00')
            ->inPeriod($this->period)->create();

        app(PeriodRecalculationService::class)->recalculate($this->period, $this->admin);

        return [$seller, $sponsor];
    }

    /**
     * The page without its <head>.
     *
     * Asset filenames are content hashes and can contain any letters at all,
     * so a build could fail this sweep on a coincidence. Only what the operator
     * actually reads is searched.
     */
    private function body(string $html): string
    {
        $start = stripos($html, '<body');

        return $start === false ? $html : substr($html, $start);
    }
}

<?php

namespace Tests\Feature\Reward;

use App\Enums\LedgerStatus;
use App\Enums\RewardType;
use App\Models\Member;
use App\Models\RegistrySale;
use App\Models\RewardLedger;
use App\Models\User;
use App\Services\DirectRewardService;
use App\Services\UplineRewardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 13 — the Reward Ledger screens.
 *
 * The exit condition for the phase is "every amount is explainable", so these
 * tests are mostly about traceability: can an operator get from a rupee to the
 * sale, the seller, the verdict or the pool that produced it, and to the run
 * that wrote it down.
 */
class RewardLedgerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /** A month that is over, so payment is allowed. */
    private string $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->period = now()->subMonths(2)->format('Y-m');
    }

    /*
    |--------------------------------------------------------------------------
    | Access
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_guest_cannot_reach_any_ledger_screen(): void
    {
        foreach ([
            route('admin.ledger.index'),
            route('admin.ledger.reconciliation'),
            route('admin.ledger.member', Member::factory()->create()),
        ] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    #[Test]
    public function the_ledger_is_now_a_real_menu_entry_rather_than_a_promise(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.ledger.index'))
            ->assertOk()
            ->assertSee('Reward Ledger')
            // The sidebar's disabled-item badge must be gone for this entry.
            ->assertDontSee('Delivered in Phase 13', false);
    }

    /*
    |--------------------------------------------------------------------------
    | The complete ledger
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_ledger_shows_every_visible_engine_in_one_table(): void
    {
        config(['rewards.visibility.upline' => true]);

        [$seller, $sponsor] = $this->chainWithSale();

        $this->actingAs($this->admin)
            ->get(route('admin.ledger.index', ['period' => $this->period]))
            ->assertOk()
            // Both engines that ran are present, on the same page.
            ->assertSee('Direct Sale')
            ->assertSee('Upline')
            ->assertSee($seller->member_code)
            ->assertSee($sponsor->member_code)
            // And every engine is named in the breakdown, even the empty ones.
            ->assertSee('Team Target')
            ->assertSee('Company Club');
    }

    #[Test]
    public function a_hidden_engine_is_absent_from_every_surface_of_the_ledger(): void
    {
        // Upline is hidden by default (2026-08-27). Its rows are still being
        // written — see the engine test below — but nothing here shows them.
        [, $sponsor] = $this->chainWithSale();

        $upline = RewardLedger::query()->ofType(RewardType::Upline)->firstOrFail();

        $this->actingAs($this->admin)
            ->get(route('admin.ledger.index', ['period' => $this->period]))
            ->assertOk()
            ->assertDontSee('Upline')
            // The 1,000 Sq.Ft. sale pays ₹40,000 direct; the ₹50,000 upline
            // pool must not appear in the total.
            ->assertSee('₹40,000.00')
            ->assertDontSee('₹50,000.00');

        // Its entry page, its filter and its member statement are all closed.
        $this->actingAs($this->admin)->get(route('admin.ledger.show', $upline->id))->assertNotFound();

        $this->actingAs($this->admin)
            ->get(route('admin.ledger.index', [
                'period' => $this->period,
                'reward_type' => RewardType::Upline->value,
            ]))
            ->assertOk()
            ->assertDontSee('Upline');

        $this->actingAs($this->admin)
            ->get(route('admin.ledger.member', $sponsor))
            ->assertOk()
            ->assertDontSee('Upline');
    }

    #[Test]
    public function hiding_an_engine_does_not_stop_it_paying(): void
    {
        // The load-bearing test for the whole arrangement. "Hide" was confirmed
        // to mean the screens, never the money: if this fails, uplines have
        // silently stopped being paid.
        $this->chainWithSale();

        $upline = RewardLedger::query()->ofType(RewardType::Upline)->first();

        $this->assertNotNull($upline, 'The hidden engine must still write to the ledger.');
        $this->assertSame('50000.00', $upline->amount);
    }

    #[Test]
    public function a_hidden_engine_cannot_be_paid_through_a_crafted_request(): void
    {
        $this->chainWithSale();

        $upline = RewardLedger::query()->ofType(RewardType::Upline)->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.ledger.paid', $upline->id))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->post(route('admin.ledger.paid-all'), [
                'period' => $this->period,
                'reward_type' => RewardType::Upline->value,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertFalse($upline->refresh()->isPaid());
    }

    #[Test]
    public function the_ledger_opens_on_the_current_month(): void
    {
        $this->chainWithSale();

        // The rewards are two months old, so the default view is empty rather
        // than showing everything the system has ever awarded.
        $this->actingAs($this->admin)
            ->get(route('admin.ledger.index'))
            ->assertOk()
            ->assertSee('No rewards match these filters');
    }

    #[Test]
    public function searching_for_a_member_looks_across_every_month(): void
    {
        [$seller] = $this->chainWithSale();

        // Pinning a search to the current month would make search look broken.
        $this->actingAs($this->admin)
            ->get(route('admin.ledger.index', ['search' => $seller->member_code]))
            ->assertOk()
            ->assertSee($seller->member_code)
            ->assertSee('every month', false);
    }

    #[Test]
    public function the_ledger_filters_by_reward_type_and_by_payment_status(): void
    {
        config(['rewards.visibility.upline' => true]);

        [$seller, $sponsor] = $this->chainWithSale();

        $this->actingAs($this->admin)
            ->get(route('admin.ledger.index', [
                'period' => $this->period,
                'reward_type' => RewardType::Upline->value,
            ]))
            ->assertOk()
            ->assertSee($sponsor->member_code)
            // The seller earned a direct reward but no upline share, so their
            // code appears only as the SOURCE of the sponsor's pool — which is
            // the point of the source column, not a stray row.
            ->assertSee($seller->member_code.' — '.$this->period)
            // The direct reward is filtered out: its own "Registry sale #"
            // source label appears on no row.
            ->assertDontSee('Registry sale #');

        RewardLedger::query()->ofType(RewardType::Direct)->update([
            'status' => LedgerStatus::Paid,
            'paid_at' => now(),
            'paid_by' => $this->admin->id,
        ]);

        $paid = $this->actingAs($this->admin)
            ->get(route('admin.ledger.index', [
                'period' => $this->period,
                'status' => LedgerStatus::Paid->value,
            ]))
            ->assertOk();

        $this->assertSame(
            1,
            RewardLedger::query()->where('status', LedgerStatus::Paid)->count(),
            'Only the direct reward should have been marked paid.',
        );

        $paid->assertSee($seller->member_code);
    }

    #[Test]
    public function the_ledger_totals_cover_the_whole_filtered_set_not_the_visible_page(): void
    {
        $member = Member::factory()->create();

        foreach (range(1, 30) as $ignored) {
            RegistrySale::factory()->forMember($member)->sqft('100.00')
                ->inPeriod($this->period)->create();
        }

        app(DirectRewardService::class)->calculate($this->period, $this->admin);

        // 30 sales × 100 Sq.Ft. × ₹40 = ₹120,000, on a 25-row page.
        $this->actingAs($this->admin)
            ->get(route('admin.ledger.index', ['period' => $this->period]))
            ->assertOk()
            ->assertSee('₹120,000.00');
    }

    /*
    |--------------------------------------------------------------------------
    | Explaining one amount
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_direct_reward_traces_back_to_its_registry_sale(): void
    {
        [$seller] = $this->chainWithSale();

        $reward = RewardLedger::query()->ofType(RewardType::Direct)->firstOrFail();
        $sale = RegistrySale::query()->firstOrFail();

        $this->actingAs($this->admin)
            ->get(route('admin.ledger.show', $reward->id))
            ->assertOk()
            ->assertSee('Registry sale #'.$sale->id)
            ->assertSee(route('admin.sales.show', $sale->id), false)
            ->assertSee('Run #'.$reward->calculation_run_id)
            ->assertSee($seller->member_code)
            // The arithmetic is spelled out, not assumed.
            ->assertSee('Sq.Ft. × rate = amount, on every row.', false);
    }

    #[Test]
    public function an_upline_share_traces_back_to_the_seller_whose_month_formed_the_pool(): void
    {
        config(['rewards.visibility.upline' => true]);

        [$seller, $sponsor] = $this->chainWithSale();

        $reward = RewardLedger::query()->ofType(RewardType::Upline)->firstOrFail();

        $this->assertSame($sponsor->id, $reward->member_id, 'The sponsor receives the share.');
        $this->assertSame($seller->id, (int) $reward->source_id, 'The seller is the source.');

        $this->actingAs($this->admin)
            ->get(route('admin.ledger.show', $reward->id))
            ->assertOk()
            ->assertSee($seller->member_code)
            ->assertSee('formed the pool this share came out of', false);
    }

    #[Test]
    public function an_upline_row_says_it_does_not_multiply_out_rather_than_looking_wrong(): void
    {
        config(['rewards.visibility.upline' => true]);

        // The row stores the SELLER's Sq.Ft. and the ₹50 rate, but pays a share
        // of the pool. An operator checking 1,000 × 50 against the amount would
        // otherwise conclude the system had underpaid.
        $this->chainWithSale();

        $reward = RewardLedger::query()->ofType(RewardType::Upline)->firstOrFail();

        $this->actingAs($this->admin)
            ->get(route('admin.ledger.show', $reward->id))
            ->assertOk()
            ->assertSee('one equal share of it', false)
            ->assertSee('Sq.Ft. × rate — the pool', false);
    }

    #[Test]
    public function an_entry_shows_the_members_other_rewards_for_the_same_month(): void
    {
        config(['rewards.visibility.upline' => true]);

        [$seller, $sponsor] = $this->chainWithSale();

        // The sponsor sells too, so they hold a direct AND an upline reward.
        RegistrySale::factory()->forMember($sponsor)->sqft('500.00')
            ->inPeriod($this->period)->create();

        app(DirectRewardService::class)->recalculate($this->period, $this->admin);
        app(UplineRewardService::class)->recalculate($this->period, $this->admin);

        $reward = RewardLedger::query()
            ->where('member_id', $sponsor->id)
            ->ofType(RewardType::Upline)
            ->firstOrFail();

        $this->actingAs($this->admin)
            ->get(route('admin.ledger.show', $reward->id))
            ->assertOk()
            ->assertSee('Total owed to this member for '.$this->period);
    }

    /*
    |--------------------------------------------------------------------------
    | The member statement
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_member_statement_gathers_every_engine_and_every_month(): void
    {
        config(['rewards.visibility.upline' => true]);

        [, $sponsor] = $this->chainWithSale();

        $this->actingAs($this->admin)
            ->get(route('admin.ledger.member', $sponsor))
            ->assertOk()
            ->assertSee($sponsor->name)
            ->assertSee('By engine')
            ->assertSee('By month')
            ->assertSee($this->period);
    }

    #[Test]
    public function the_member_profile_links_out_to_the_statement(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.members.show', $member))
            ->assertOk()
            ->assertSee(route('admin.ledger.member', $member), false);
    }

    /*
    |--------------------------------------------------------------------------
    | Payment — the control Direct and Upline never had
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_direct_reward_can_finally_be_marked_paid(): void
    {
        $this->chainWithSale();

        $reward = RewardLedger::query()->ofType(RewardType::Direct)->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.ledger.paid', $reward->id))
            ->assertRedirect();

        $reward->refresh();

        $this->assertTrue($reward->isPaid());
        $this->assertSame($this->admin->id, $reward->paid_by);
        $this->assertNotNull($reward->paid_at);
    }

    #[Test]
    public function an_upline_share_can_finally_be_marked_paid(): void
    {
        config(['rewards.visibility.upline' => true]);

        $this->chainWithSale();

        $reward = RewardLedger::query()->ofType(RewardType::Upline)->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.ledger.paid', $reward->id))
            ->assertRedirect();

        $this->assertTrue($reward->refresh()->isPaid());
    }

    #[Test]
    public function a_month_that_has_not_finished_cannot_be_paid(): void
    {
        $member = Member::factory()->create();
        $current = now()->format('Y-m');

        RegistrySale::factory()->forMember($member)->sqft('1000.00')->inPeriod($current, 1)->create();
        app(DirectRewardService::class)->calculate($current, $this->admin);

        $reward = RewardLedger::query()->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.ledger.paid', $reward->id))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertFalse($reward->refresh()->isPaid());
    }

    #[Test]
    public function paying_all_covers_one_engine_at_a_time(): void
    {
        $this->chainWithSale();

        $this->actingAs($this->admin)
            ->post(route('admin.ledger.paid-all'), [
                'period' => $this->period,
                'reward_type' => RewardType::Direct->value,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        // Direct is settled; the upline share is untouched, because the four
        // engines are reviewed separately.
        $this->assertSame(
            0,
            RewardLedger::query()->ofType(RewardType::Direct)->unpaid()->count(),
        );
        $this->assertGreaterThan(
            0,
            RewardLedger::query()->ofType(RewardType::Upline)->unpaid()->count(),
        );
    }

    #[Test]
    public function an_unknown_reward_type_is_refused_rather_than_paying_everything(): void
    {
        $this->chainWithSale();

        $this->actingAs($this->admin)
            ->post(route('admin.ledger.paid-all'), [
                'period' => $this->period,
                'reward_type' => 'everything',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, RewardLedger::query()->paid()->count());
    }

    #[Test]
    public function a_reward_already_paid_cannot_be_paid_again(): void
    {
        $this->chainWithSale();

        $reward = RewardLedger::query()->ofType(RewardType::Direct)->firstOrFail();

        $this->actingAs($this->admin)->post(route('admin.ledger.paid', $reward->id));
        $paidAt = $reward->refresh()->paid_at;

        $this->actingAs($this->admin)
            ->post(route('admin.ledger.paid', $reward->id))
            ->assertSessionHas('error');

        $this->assertEquals($paidAt, $reward->refresh()->paid_at);
    }

    /*
    |--------------------------------------------------------------------------
    | Downloads
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_ledger_downloads_in_all_three_formats_with_the_month_on_the_file(): void
    {
        $this->chainWithSale();

        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $response = $this->actingAs($this->admin)
                ->get(route('admin.ledger.export', ['format' => $format, 'period' => $this->period]));

            $response->assertOk();

            $this->assertStringContainsString(
                'reward-ledger-'.$this->period,
                $response->headers->get('content-disposition'),
                "The {$format} download must carry its month in the filename.",
            );
        }
    }

    #[Test]
    public function an_unknown_download_format_is_not_found(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/ledger/export/docx')
            ->assertNotFound();
    }

    #[Test]
    public function the_download_carries_the_filters_the_page_is_showing(): void
    {
        config(['rewards.visibility.upline' => true]);

        [$seller, $sponsor] = $this->chainWithSale();

        $csv = $this->actingAs($this->admin)
            ->get(route('admin.ledger.export', [
                'format' => 'csv',
                'period' => $this->period,
                'reward_type' => RewardType::Upline->value,
            ]))
            ->streamedContent();

        $this->assertStringContainsString($sponsor->member_code, $csv);
        $this->assertStringNotContainsString($seller->member_code, $csv);
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
    private function chainWithSale(): array
    {
        $sponsor = Member::factory()->create();
        $seller = Member::factory()->sponsoredBy($sponsor)->create();

        RegistrySale::factory()->forMember($seller)->sqft('1000.00')
            ->inPeriod($this->period)->create();

        app(DirectRewardService::class)->calculate($this->period, $this->admin);
        app(UplineRewardService::class)->calculate($this->period, $this->admin);

        return [$seller, $sponsor];
    }
}

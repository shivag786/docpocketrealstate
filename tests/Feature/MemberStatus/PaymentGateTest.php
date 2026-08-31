<?php

namespace Tests\Feature\MemberStatus;

use App\Enums\LedgerStatus;
use App\Models\Member;
use App\Models\RegistrySale;
use App\Models\RewardLedger;
use App\Models\User;
use App\Modules\MemberStatus\Enums\CalculatedStatus;
use App\Modules\MemberStatus\Services\StatusRecalculationService;
use App\Modules\MemberStatus\Support\StatusConfig;
use App\Services\DirectRewardService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;

/**
 * The payment gate (client rule, 2026-08-25).
 *
 *      A member who is not ACTIVE can be looked at in full — every reward,
 *      every amount, paid and unpaid — but an admin may not confirm a payment
 *      to them.
 *
 * The rewards here are produced by the application's OWN Direct reward engine
 * and paid through its OWN payment service, so these tests exercise the real
 * money path with the module's condition in front of it.
 */
class PaymentGateTest extends MemberStatusTestCase
{
    protected bool $reportEnabled = true;

    private const PERIOD = '2026-01';

    private User $admin;

    private Member $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();

        $this->seller = Member::factory()->root()->create([
            'name' => 'Shiva',
            'joining_date' => '2026-01-01',
        ]);

        RegistrySale::factory()->withoutDetails()->forMember($this->seller)
            ->sqft('1000.00')
            ->create(['registry_date' => '2026-01-10', 'sale_date' => '2026-01-10']);

        // Real rewards, from the application's real engine.
        app(DirectRewardService::class)->calculate(self::PERIOD, $this->admin);
    }

    private function statusOn(string $date): void
    {
        app(StatusRecalculationService::class)->recalculateAll(CarbonImmutable::parse($date));
    }

    private function reward(): RewardLedger
    {
        return RewardLedger::query()->where('member_id', $this->seller->id)->firstOrFail();
    }

    private function payUrl(?RewardLedger $reward = null): string
    {
        $reward ??= $this->reward();

        return "/admin/member-status/members/{$this->seller->id}/rewards/{$reward->id}/pay";
    }

    #[Test]
    public function an_active_member_can_be_paid(): void
    {
        $this->statusOn('2026-01-20');

        $this->assertSame(
            CalculatedStatus::Active,
            app(StatusRecalculationService::class)->currentStatus($this->seller->id),
        );

        $this->actingAs($this->admin)
            ->postJson($this->payUrl())
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(LedgerStatus::Paid, $this->reward()->status);
        $this->assertSame($this->admin->id, $this->reward()->paid_by);
    }

    #[Test]
    public function a_pending_member_cannot_be_paid(): void
    {
        // 2026-06-01 is 142 days after the sale: past 90, short of 180.
        $this->statusOn('2026-06-01');

        $this->assertSame(
            CalculatedStatus::Pending,
            app(StatusRecalculationService::class)->currentStatus($this->seller->id),
        );

        $response = $this->actingAs($this->admin)
            ->postJson($this->payUrl())
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertStringContainsString('PENDING', $response->json('message'));
        $this->assertStringContainsString('on hold', $response->json('message'));

        $this->assertSame(
            LedgerStatus::Posted,
            $this->reward()->status,
            'A blocked payment must leave the ledger untouched.'
        );
    }

    #[Test]
    public function an_inactive_member_cannot_be_paid_either(): void
    {
        $this->statusOn('2026-08-01'); // 203 days after the sale

        $this->assertSame(
            CalculatedStatus::Inactive,
            app(StatusRecalculationService::class)->currentStatus($this->seller->id),
        );

        $this->actingAs($this->admin)->postJson($this->payUrl())->assertStatus(422);

        $this->assertSame(LedgerStatus::Posted, $this->reward()->status);
    }

    #[Test]
    public function the_blocked_statuses_are_configuration_not_code(): void
    {
        // A business that decides PENDING should still be payable changes one
        // config line, not this module.
        config()->set('member_status.payment.blocked_statuses', ['INACTIVE']);
        $this->app->forgetInstance(StatusConfig::class);

        $this->statusOn('2026-06-01');

        $this->actingAs($this->admin)->postJson($this->payUrl())->assertOk();

        $this->assertSame(LedgerStatus::Paid, $this->reward()->status);
    }

    #[Test]
    public function a_blocked_member_can_still_see_every_reward(): void
    {
        $this->statusOn('2026-06-01');

        $this->actingAs($this->admin)
            ->getJson("/admin/member-status/members/{$this->seller->id}/rewards")
            ->assertOk()
            ->assertJsonPath('data.status.value', 'PENDING')
            ->assertJsonPath('data.payment.allowed', false)
            ->assertJsonPath('data.summary.unpaid', 1)
            ->assertJsonCount(1, 'data.rewards')
            ->assertJsonPath('data.rewards.0.type_label', 'Direct Sale')
            ->assertJsonPath('data.member.name', 'Shiva');
    }

    #[Test]
    public function a_reward_belonging_to_another_member_cannot_be_paid_through_this_one(): void
    {
        $this->statusOn('2026-01-20');

        $other = Member::factory()->root()->create(['joining_date' => '2026-01-01']);

        $this->actingAs($this->admin)
            ->postJson("/admin/member-status/members/{$other->id}/rewards/{$this->reward()->id}/pay")
            ->assertStatus(404);

        $this->assertSame(LedgerStatus::Posted, $this->reward()->status);
    }

    #[Test]
    public function paying_all_of_a_members_rewards_is_gated_the_same_way(): void
    {
        $this->statusOn('2026-06-01');

        $url = "/admin/member-status/members/{$this->seller->id}/rewards/pay-all";

        $this->actingAs($this->admin)->postJson($url)->assertStatus(422);
        $this->assertSame(LedgerStatus::Posted, $this->reward()->status);

        $this->statusOn('2026-01-20');

        $this->actingAs($this->admin)->postJson($url)->assertOk();
        $this->assertSame(LedgerStatus::Paid, $this->reward()->status);
    }

    #[Test]
    public function the_hosts_own_refusals_still_apply_to_an_active_member(): void
    {
        $this->statusOn('2026-01-20');

        $this->actingAs($this->admin)->postJson($this->payUrl())->assertOk();

        // Already paid — the application's own rule, surfaced through the panel.
        $this->actingAs($this->admin)
            ->postJson($this->payUrl())
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function the_table_shows_a_locked_button_for_a_blocked_member(): void
    {
        $this->statusOn('2026-06-01');

        $this->actingAs($this->admin)
            ->get('/admin/member-status')
            ->assertOk()
            ->assertSee('payment is on hold', false)
            ->assertSee('bi-lock', false);
    }

    #[Test]
    public function a_guest_cannot_reach_the_payment_endpoints(): void
    {
        $this->statusOn('2026-01-20');

        $this->postJson($this->payUrl())->assertStatus(401);
        $this->assertSame(LedgerStatus::Posted, $this->reward()->status);
    }
}

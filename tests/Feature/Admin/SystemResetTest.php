<?php

namespace Tests\Feature\Admin;

use App\Enums\MemberStatus;
use App\Models\CompanyClubSetting;
use App\Models\CompanySetting;
use App\Models\Member;
use App\Models\Project;
use App\Models\Property;
use App\Models\RegistrySale;
use App\Models\User;
use App\Services\MemberService;
use App\Services\SystemResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The developer system reset.
 *
 * This is the most dangerous button in the product, so the tests are weighted
 * towards what must NOT happen: it must not be reachable with the flag off, it
 * must not fire without the typed word, and it must not take the admin's own
 * login or the company's branding with it.
 */
class SystemResetTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /**
     * The gate is read per request by the `developer` middleware, so switching
     * it here is enough — no environment juggling and no re-registering routes.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['company.developer_tools' => true]);

        $this->admin = User::factory()->admin()->create();
    }

    /**
     * A system with something in every table the reset touches.
     */
    private function seedBusinessData(): Member
    {
        $project = Project::factory()->create();
        Property::factory()->for($project)->create();

        $sponsor = Member::factory()->create();
        $member = Member::factory()->sponsoredBy($sponsor)->create();

        RegistrySale::factory()->forMember($member)->sqft('1000.00')->create();

        return $member;
    }

    // -----------------------------------------------------------------
    // Reachability. The flag-OFF cases live in SystemResetDisabledTest.
    // -----------------------------------------------------------------

    #[Test]
    public function a_guest_cannot_reach_the_reset_page(): void
    {
        $this->get(route('admin.settings.developer'))->assertRedirect(route('login'));
    }

    #[Test]
    public function an_admin_can_open_the_reset_page_when_developer_tools_are_on(): void
    {
        $this->seedBusinessData();

        $this->actingAs($this->admin)
            ->get(route('admin.settings.developer'))
            ->assertOk()
            ->assertSee('Reset the system')
            ->assertSee('registry_sales');
    }

    // -----------------------------------------------------------------
    // The typed confirmation
    // -----------------------------------------------------------------

    #[Test]
    public function nothing_is_deleted_without_the_confirmation_word(): void
    {
        $this->seedBusinessData();

        $this->actingAs($this->admin)
            ->post(route('admin.settings.developer.reset'), ['confirmation' => ''])
            ->assertSessionHasErrors('confirmation');

        $this->assertDatabaseCount('members', 2);
        $this->assertDatabaseCount('registry_sales', 1);
    }

    #[Test]
    public function nothing_is_deleted_when_the_confirmation_word_is_wrong(): void
    {
        $this->seedBusinessData();

        foreach (['reset', 'Reset', 'RESET NOW', 'yes'] as $attempt) {
            $this->actingAs($this->admin)
                ->post(route('admin.settings.developer.reset'), ['confirmation' => $attempt])
                ->assertSessionHasErrors('confirmation');
        }

        $this->assertDatabaseCount('members', 2);
    }

    // -----------------------------------------------------------------
    // What it clears, and what it must not
    // -----------------------------------------------------------------

    #[Test]
    public function the_reset_clears_every_business_table(): void
    {
        $this->seedBusinessData();

        $this->actingAs($this->admin)
            ->post(route('admin.settings.developer.reset'), ['confirmation' => 'RESET'])
            ->assertRedirect(route('admin.settings.developer'))
            ->assertSessionHas('success');

        foreach (SystemResetService::CLEARS as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    #[Test]
    public function the_reset_keeps_the_admin_login_so_nobody_is_locked_out(): void
    {
        $this->seedBusinessData();

        $this->actingAs($this->admin)
            ->post(route('admin.settings.developer.reset'), ['confirmation' => 'RESET']);

        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);

        // Still able to use the panel afterwards.
        $this->actingAs($this->admin->fresh())
            ->get(route('admin.members.index'))
            ->assertOk();
    }

    #[Test]
    public function the_reset_keeps_the_company_branding(): void
    {
        CompanySetting::current()->forceFill([
            'company_name' => 'Dream Properties',
            'authority_name' => 'R. Sharma',
        ])->save();

        $clubName = CompanyClubSetting::current()->name();

        $this->seedBusinessData();

        $this->actingAs($this->admin)
            ->post(route('admin.settings.developer.reset'), ['confirmation' => 'RESET']);

        $this->assertSame('Dream Properties', CompanySetting::current()->fresh()->company_name);
        $this->assertSame('R. Sharma', CompanySetting::current()->fresh()->authority_name);
        $this->assertSame($clubName, CompanyClubSetting::current()->fresh()->name());
    }

    #[Test]
    public function member_codes_restart_from_the_configured_first_code_after_a_reset(): void
    {
        $this->seedBusinessData();

        $this->actingAs($this->admin)
            ->post(route('admin.settings.developer.reset'), ['confirmation' => 'RESET']);

        // Created through MemberService, not the factory: the factory assigns
        // codes from its own per-process counter and never touches
        // MemberCodeGenerator, so asserting against it would prove nothing
        // about what an admin using the form actually gets.
        $fresh = app(MemberService::class)->create([
            'name' => 'First Member After Reset',
            'mobile' => '9000000001',
            'joining_date' => now()->toDateString(),
            'status' => MemberStatus::Active->value,
            'designation' => config('company.designations.default'),
        ]);

        $this->assertSame(
            config('members.code.prefix').config('members.code.start_at'),
            $fresh->member_code,
        );
    }

    #[Test]
    public function resetting_an_already_empty_system_is_harmless(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.settings.developer.reset'), ['confirmation' => 'RESET'])
            ->assertRedirect(route('admin.settings.developer'))
            ->assertSessionHas('info');
    }

    #[Test]
    public function no_preserved_table_appears_in_the_clear_list(): void
    {
        // A guard against a careless future edit: the two lists must not
        // intersect, or the reset would take the admin's login with it.
        $this->assertSame(
            [],
            array_intersect(SystemResetService::CLEARS, SystemResetService::PRESERVES),
        );
    }
}

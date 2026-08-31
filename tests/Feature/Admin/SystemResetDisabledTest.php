<?php

namespace Tests\Feature\Admin;

use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The reset with DEVELOPER_TOOLS off — the state the system ships in.
 *
 * The distinction being asserted is 404, not 403. A refusal would confirm the
 * page is there; nothing in the response should tell a stranger that a reset
 * button exists behind a flag.
 */
class SystemResetDisabledTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['company.developer_tools' => false]);
    }

    // URLs are written out rather than built with route(): the routes DO
    // exist now, and the point of these tests is that the gate refuses them.

    #[Test]
    public function the_reset_page_is_not_found(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/admin/settings/developer')
            ->assertNotFound();
    }

    #[Test]
    public function the_reset_endpoint_cannot_be_posted_to_and_deletes_nothing(): void
    {
        $admin = User::factory()->admin()->create();
        Member::factory()->count(3)->create();

        $this->actingAs($admin)
            ->post('/admin/settings/developer/reset', ['confirmation' => 'RESET'])
            ->assertNotFound();

        $this->assertDatabaseCount('members', 3);
    }

    #[Test]
    public function the_settings_pages_do_not_offer_a_developer_tab(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertDontSee('Developer');
    }
}

<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create([
            'password' => 'OldPassword1',
        ]);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'current_password' => 'OldPassword1',
            'password' => 'BrandNew123',
            'password_confirmation' => 'BrandNew123',
        ], $overrides);
    }

    #[Test]
    public function a_guest_cannot_reach_the_change_password_page(): void
    {
        $this->get(route('admin.settings.password'))->assertRedirect(route('login'));
    }

    #[Test]
    public function an_admin_can_open_the_change_password_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.settings.password'))
            ->assertOk()
            ->assertSee('Change password');
    }

    #[Test]
    public function an_admin_can_change_their_password(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.password.update'), $this->payload())
            ->assertRedirect(route('admin.settings.password'))
            ->assertSessionHas('success');

        $this->assertTrue(Hash::check('BrandNew123', $this->admin->fresh()->password));
    }

    #[Test]
    public function the_new_password_actually_works_at_sign_in(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.password.update'), $this->payload());

        auth()->logout();

        $this->post(route('login'), [
            'email' => $this->admin->email,
            'password' => 'BrandNew123',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($this->admin);
    }

    #[Test]
    public function the_old_password_stops_working(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.password.update'), $this->payload());

        auth()->logout();

        $this->post(route('login'), [
            'email' => $this->admin->email,
            'password' => 'OldPassword1',
        ])->assertSessionHasErrors();

        $this->assertGuest();
    }

    #[Test]
    public function the_current_password_must_be_correct(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.password.update'), $this->payload([
                'current_password' => 'NotMyPassword1',
            ]))
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('OldPassword1', $this->admin->fresh()->password));
    }

    #[Test]
    public function the_two_new_passwords_must_match(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.password.update'), $this->payload([
                'password_confirmation' => 'SomethingElse1',
            ]))
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('OldPassword1', $this->admin->fresh()->password));
    }

    #[Test]
    public function the_new_password_must_differ_from_the_current_one(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.password.update'), $this->payload([
                'password' => 'OldPassword1',
                'password_confirmation' => 'OldPassword1',
            ]))
            ->assertSessionHasErrors('password');
    }

    #[Test]
    public function a_weak_password_is_rejected(): void
    {
        foreach (['short1', 'alllettersnodigits', '12345678'] as $weak) {
            $this->actingAs($this->admin)
                ->put(route('admin.settings.password.update'), $this->payload([
                    'password' => $weak,
                    'password_confirmation' => $weak,
                ]))
                ->assertSessionHasErrors('password');
        }

        $this->assertTrue(Hash::check('OldPassword1', $this->admin->fresh()->password));
    }

    // -----------------------------------------------------------------
    // The readable copy.
    //
    // CLIENT DECISION (2026-08-31): passwords are also stored unencrypted so
    // they can be looked up. These tests pin that behaviour down deliberately —
    // both that the readable copy IS written, and that it changes nothing about
    // how authentication works. See the add_password_plain_to_users migration.
    // -----------------------------------------------------------------

    #[Test]
    public function the_readable_copy_is_recorded_alongside_the_hash(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.password.update'), $this->payload());

        $stored = DB::table('users')->where('id', $this->admin->id)->first();

        $this->assertSame('BrandNew123', $stored->password_plain);

        // And the hash is a real hash, not the plain value.
        $this->assertNotSame('BrandNew123', $stored->password);
        $this->assertTrue(Hash::check('BrandNew123', $stored->password));
    }

    #[Test]
    public function the_readable_copy_is_shown_on_the_settings_page(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.password.update'), $this->payload());

        $this->actingAs($this->admin->fresh())
            ->get(route('admin.settings.password'))
            ->assertOk()
            ->assertSee('BrandNew123');
    }

    #[Test]
    public function a_rejected_change_does_not_update_the_readable_copy(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.password.update'), $this->payload([
                'current_password' => 'WrongPassword1',
            ]));

        $this->assertNull(
            DB::table('users')->where('id', $this->admin->id)->value('password_plain'),
        );
    }

    #[Test]
    public function the_readable_copy_never_leaks_into_serialised_output(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.password.update'), $this->payload());

        $json = $this->admin->fresh()->toJson();

        $this->assertStringNotContainsString('BrandNew123', $json);
        $this->assertStringNotContainsString('password_plain', $json);
    }

    #[Test]
    public function authentication_still_uses_the_hash_not_the_readable_copy(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.password.update'), $this->payload());

        // Corrupt the readable copy. Sign-in must be entirely unaffected,
        // because nothing authenticates against that column.
        DB::table('users')->where('id', $this->admin->id)
            ->update(['password_plain' => 'not-the-real-password']);

        auth()->logout();

        $this->post(route('login'), [
            'email' => $this->admin->email,
            'password' => 'BrandNew123',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($this->admin);
    }
}

<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_login_screen_can_be_rendered(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Sign in');
    }

    #[Test]
    public function an_active_admin_can_authenticate(): void
    {
        $user = User::factory()->admin()->create([
            'email' => 'admin@example.test',
            'password' => 'secret-password',
        ]);

        $this->post(route('login'), [
            'email' => 'admin@example.test',
            'password' => 'secret-password',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function an_active_manager_can_authenticate(): void
    {
        User::factory()->manager()->create([
            'email' => 'manager@example.test',
            'password' => 'secret-password',
        ]);

        $this->post(route('login'), [
            'email' => 'manager@example.test',
            'password' => 'secret-password',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticated();
    }

    #[Test]
    public function authentication_fails_with_a_wrong_password(): void
    {
        User::factory()->admin()->create([
            'email' => 'admin@example.test',
            'password' => 'secret-password',
        ]);

        $this->post(route('login'), [
            'email' => 'admin@example.test',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    #[Test]
    public function an_inactive_user_cannot_authenticate_even_with_correct_credentials(): void
    {
        User::factory()->admin()->inactive()->create([
            'email' => 'disabled@example.test',
            'password' => 'secret-password',
        ]);

        $this->post(route('login'), [
            'email' => 'disabled@example.test',
            'password' => 'secret-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    #[Test]
    public function email_and_password_are_required(): void
    {
        $this->post(route('login'), [])
            ->assertSessionHasErrors(['email', 'password']);

        $this->assertGuest();
    }

    #[Test]
    public function a_successful_login_records_the_timestamp(): void
    {
        $user = User::factory()->admin()->create([
            'email' => 'admin@example.test',
            'password' => 'secret-password',
        ]);

        $this->assertNull($user->last_login_at);

        $this->post(route('login'), [
            'email' => 'admin@example.test',
            'password' => 'secret-password',
        ]);

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    #[Test]
    public function repeated_failures_are_rate_limited(): void
    {
        User::factory()->admin()->create([
            'email' => 'admin@example.test',
            'password' => 'secret-password',
        ]);

        foreach (range(1, 5) as $attempt) {
            $this->post(route('login'), [
                'email' => 'admin@example.test',
                'password' => 'wrong-password',
            ]);
        }

        // The 6th attempt is blocked by the throttle even with the RIGHT password.
        $response = $this->post(route('login'), [
            'email' => 'admin@example.test',
            'password' => 'secret-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString(
            'Too many login attempts',
            session('errors')->first('email')
        );
        $this->assertGuest();
    }

    #[Test]
    public function a_user_can_sign_out(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    #[Test]
    public function an_authenticated_user_is_redirected_away_from_the_login_screen(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('login'))
            ->assertRedirect();
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Locks the AJAX envelope in place. Every later phase depends on this shape,
 * so a change here should break loudly rather than silently.
 */
class ApiResponseConventionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_success_response_uses_the_standard_envelope(): void
    {
        Route::get('/_test/ok', fn () => ApiResponse::success(['id' => 7], 'Saved.'));

        $this->getJson('/_test/ok')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Saved.',
                'data' => ['id' => 7],
                'errors' => null,
            ]);
    }

    #[Test]
    public function an_error_response_uses_the_standard_envelope(): void
    {
        Route::get('/_test/fail', fn () => ApiResponse::error('Nope.'));

        $this->getJson('/_test/fail')
            ->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => 'Nope.',
                'data' => null,
                'errors' => null,
            ]);
    }

    #[Test]
    public function validation_failures_return_422_in_the_standard_envelope(): void
    {
        Route::post('/_test/validate', function () {
            request()->validate(['sqft' => ['required', 'numeric']]);
        });

        $this->postJson('/_test/validate', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null)
            ->assertJsonStructure(['success', 'message', 'data', 'errors' => ['sqft']]);
    }

    #[Test]
    public function an_unauthenticated_ajax_request_returns_401_not_a_redirect(): void
    {
        $this->getJson(route('admin.dashboard'))
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message', 'data', 'errors']);
    }

    #[Test]
    public function a_missing_route_returns_404_in_the_standard_envelope(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->getJson('/admin/does-not-exist')
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function a_deactivated_user_making_an_ajax_request_gets_401(): void
    {
        $user = User::factory()->admin()->inactive()->create();

        $this->actingAs($user)
            ->getJson(route('admin.dashboard'))
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }
}

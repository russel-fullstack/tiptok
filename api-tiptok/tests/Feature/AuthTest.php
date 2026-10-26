<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $override = []): array
    {
        return array_merge([
            'name' => 'Alice', 'username' => 'alice', 'email' => 'alice@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ], $override);
    }

    public function test_user_can_register(): void
    {
        $this->postJson('/register', $this->payload())->assertSuccessful();
        $this->assertDatabaseHas('users', ['email' => 'alice@example.com', 'username' => 'alice']);
    }

    public function test_duplicate_email_or_username_is_rejected(): void
    {
        User::factory()->create(['email' => 'alice@example.com', 'username' => 'alice']);

        $this->postJson('/register', $this->payload())->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'username']);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'mauvais'])->assertUnprocessable();
    }

    public function test_authenticated_user_is_returned_by_api_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/user')
            ->assertOk()->assertJsonPath('id', $user->id)->assertJsonMissingPath('password');
    }

    public function test_logout_ends_the_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/logout')->assertSuccessful();
        $this->assertGuest('web');
    }

    public function test_protected_route_requires_authentication(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
        $this->postJson('/api/posts', ['content' => 'x'])->assertUnauthorized();
    }
}

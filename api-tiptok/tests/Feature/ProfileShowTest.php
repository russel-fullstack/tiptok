<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_profile_is_visible_without_login(): void
    {
        $user = User::factory()->create();

        $this->getJson("/api/v1/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('data.username', $user->username)
            ->assertJsonPath('data.is_following', false);
    }

    public function test_unknown_profile_returns_404(): void
    {
        $this->getJson('/api/v1/users/999999')->assertNotFound();
    }

    public function test_private_data_is_never_exposed(): void
    {
        $user = User::factory()->create();
        $json = $this->getJson("/api/v1/users/{$user->id}")->assertOk()->json('data');

        foreach (['email', 'password', 'remember_token', 'role', 'avatar_path'] as $key) {
            $this->assertArrayNotHasKey($key, $json);
        }
    }

    public function test_is_following_matches_the_authenticated_viewer(): void
    {
        [$viewer, $target] = User::factory()->count(2)->create();
        $viewer->following()->attach($target->id);

        $this->actingAs($viewer)->getJson("/api/v1/users/{$target->id}")
            ->assertJsonPath('data.is_following', true)
            ->assertJsonPath('data.followers_count', 1);
    }
}

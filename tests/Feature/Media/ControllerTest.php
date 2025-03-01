<?php

namespace Tests\Feature\Media;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_index_without_path(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
    }

    public function test_media_index_with_valid_path(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard', ['path' => 'valid']));

        $response->assertOk();
    }

    public function test_media_index_with_invalid_path(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard', ['path' => 'invalid']));

        $response->assertNotFound();
    }

    public function test_media_index_with_valid_subpath(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard', ['path' => 'valid/child']));

        $response->assertOk();
    }

    public function test_media_index_with_invalid_subpath(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard', ['path' => 'valid/invalid']));

        $response->assertNotFound();
    }
}

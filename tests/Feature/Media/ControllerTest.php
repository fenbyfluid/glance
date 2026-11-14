<?php

namespace Tests\Feature\Media;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.stores.apc.driver' => 'array']);
        $this->withoutVite();

        $parentId = null;
        foreach (['', 'valid', 'valid/child'] as $path) {
            $info = new \SplFileInfo(config('media.path').'/'.$path);
            $parentId = DB::table('indexed_directories')->insertGetId([
                'parent_id' => $parentId,
                'name' => $path === '' ? '' : basename($path),
                'path' => $path,
                'inode' => $info->getInode(),
                'mtime' => $info->getMTime(),
            ]);
        }
    }

    public function test_media_index_without_path(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
    }

    public function test_media_index_with_valid_path(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard', ['path' => 'valid']).'/?page=1');

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

        $response = $this->actingAs($user)->get(route('dashboard', ['path' => 'valid/child']).'/?page=1');

        $response->assertOk();
    }

    public function test_media_index_with_invalid_subpath(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard', ['path' => 'valid/invalid']));

        $response->assertNotFound();
    }
}

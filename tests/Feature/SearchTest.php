<?php

namespace Tests\Feature;

use App\Models\AccessControlEntry;
use App\Models\IndexedDirectory;
use App\Models\IndexedFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    private string $indexPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->indexPath = sys_get_temp_dir().'/glance-search-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->indexPath);

        config([
            'scout.queue' => false,
            'scout.tntsearch.storage' => $this->indexPath,
            // The engine connects to the source database itself on construction, which is MySQL outside of tests.
            'scout.tntsearch.driver' => 'sqlite',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->indexPath);

        parent::tearDown();
    }

    public function test_models_are_indexed_when_created(): void
    {
        $user = $this->userWithRootAccess();

        // Created through Eloquent like the indexing jobs do, relying on Scout's model observer to index them.
        $root = IndexedDirectory::create(['name' => '', 'path' => '', 'inode' => 1, 'mtime' => 1]);
        $directory = IndexedDirectory::create(['parent_id' => $root->id, 'name' => 'needle-dir', 'path' => 'needle-dir', 'inode' => 1, 'mtime' => 1]);
        $file = IndexedFile::create([
            'directory_id' => $directory->id,
            'name' => 'needle-file.txt',
            'kind' => 'text',
            'inode' => 1,
            'mtime' => 1,
            'size' => 1,
            'mime_type' => 'text/plain',
            'oshash' => str_repeat('0', 16),
        ]);
        $file->extractedText()->createMany([['file_id' => $file->id, 'text' => 'a haystack with a needle']]);

        $response = $this->actingAs($user)->getJson('/_search/name/needle')->assertOk();
        $this->assertSame(['needle-dir', 'needle-file.txt'], $this->resultNames($response->json('results')));

        $response = $this->actingAs($user)->getJson('/_search/content/haystack')->assertOk();
        $this->assertSame(['needle-file.txt'], $this->resultNames($response->json('results')));
    }

    private function userWithRootAccess(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $this->grant($user, '');

        return $user;
    }

    private function grant(User $user, string $path): void
    {
        AccessControlEntry::firstOrCreate(['path' => $path])->users()->attach($user);
    }

    /**
     * @return list<string>
     */
    private function resultNames(array $results): array
    {
        $names = array_column($results, 'name');
        sort($names, SORT_STRING);

        return $names;
    }
}

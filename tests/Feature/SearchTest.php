<?php

namespace Tests\Feature;

use App\Models\AccessControlEntry;
use App\Models\IndexedDirectory;
use App\Models\IndexedFile;
use App\Models\IndexedFileText;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Laravel\Scout\Jobs\MakeSearchable;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    private string $indexPath;

    /** @var array<string, int> */
    private array $directoryIds = [];

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
            // Small enough that the per-keyword cap is hit by the fixtures below.
            'scout.tntsearch.maxDocs' => 50,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->indexPath);

        parent::tearDown();
    }

    public function test_content_search_finds_matches_under_path_crowded_out_by_matches_elsewhere(): void
    {
        $user = $this->userWithRootAccess();

        // Many strong matches outside the requested path, which fill the index's per-keyword cap on their own.
        for ($i = 0; $i < 80; $i++) {
            $this->addText('Outside', "outside-$i.txt", 'needle needle needle needle');
        }
        $this->addText('Inside', 'a.txt', 'a needle in a haystack');
        $this->addText('Inside/Deeper', 'b.txt', 'another needle in another haystack');
        $this->addText('Insider', 'c.txt', 'a needle next door');

        $this->buildIndexes();

        $response = $this->actingAs($user)->getJson('/_search/content/needle?path=Inside')->assertOk();

        $this->assertFalse($response->json('overheated'));
        $this->assertSame(['a.txt', 'b.txt'], $this->resultNames($response->json('results')));
    }

    public function test_content_search_only_returns_accessible_files(): void
    {
        $user = User::factory()->create();
        AccessControlEntry::create(['path' => '']);
        $this->grant($user, 'Allowed');
        AccessControlEntry::create(['path' => 'Allowed/Denied']);
        $this->grant($user, 'Allowed/Denied/Regranted');

        for ($i = 0; $i < 80; $i++) {
            $this->addText('Hidden', "hidden-$i.txt", 'needle needle needle needle');
        }
        $this->addText('Allowed', 'visible.txt', 'a needle');
        $this->addText('Allowed/Sub', 'nested.txt', 'a needle');
        $this->addText('Allowed/Denied', 'denied.txt', 'a needle');
        $this->addText('Allowed/Denied/Regranted', 'regranted.txt', 'a needle');
        // Shares a name prefix with an allowed directory, but isn't within it.
        $this->addText('AllowedNot', 'sibling.txt', 'a needle');

        $this->buildIndexes();

        $response = $this->actingAs($user)->getJson('/_search/content/needle')->assertOk();

        $this->assertFalse($response->json('overheated'));
        $this->assertSame(['nested.txt', 'regranted.txt', 'visible.txt'], $this->resultNames($response->json('results')));

        // And combined with a path filter.
        $response = $this->actingAs($user)->getJson('/_search/content/needle?path=Allowed/Denied')->assertOk();
        $this->assertSame(['regranted.txt'], $this->resultNames($response->json('results')));
    }

    public function test_content_search_with_no_access_returns_nothing(): void
    {
        $user = User::factory()->create();
        AccessControlEntry::create(['path' => '']);

        $this->addText('Somewhere', 'file.txt', 'a needle');

        $this->buildIndexes();

        $response = $this->actingAs($user)->getJson('/_search/content/needle')->assertOk();

        $this->assertSame([], $response->json('results'));
    }

    public function test_content_search_respects_admin_impersonation(): void
    {
        $admin = $this->userWithRootAccess(['is_admin' => true]);
        $this->grant($admin, 'Allowed');
        $user = User::factory()->create();
        $this->grant($user, 'Allowed');

        $this->addText('Allowed', 'visible.txt', 'a needle');
        $this->addText('Other', 'other.txt', 'a needle');

        $this->buildIndexes();

        $response = $this->actingAs($admin)->getJson('/_search/content/needle')->assertOk();
        $this->assertSame(['other.txt', 'visible.txt'], $this->resultNames($response->json('results')));

        $response = $this->actingAs($admin)
            ->withSession(['admin_impersonating_user' => $user->getKey()])
            ->getJson('/_search/content/needle')
            ->assertOk();
        $this->assertSame(['visible.txt'], $this->resultNames($response->json('results')));
    }

    public function test_name_search_filters_files_and_directories_by_path_and_access(): void
    {
        $user = User::factory()->create();
        AccessControlEntry::create(['path' => '']);
        $this->grant($user, 'Allowed');
        AccessControlEntry::create(['path' => 'Allowed/Denied']);

        for ($i = 0; $i < 80; $i++) {
            $this->addFile('Hidden', "needle-$i.txt");
        }
        $this->addFile('Allowed', 'needle-visible.txt');
        $this->addFile('Allowed/Denied', 'needle-denied.txt');
        $this->addFile('AllowedNot', 'needle-sibling.txt');
        $this->directoryId('Allowed/needle-dir');
        $this->directoryId('Allowed/Denied/needle-denied-dir');
        $this->directoryId('Hidden/needle-hidden-dir');

        $this->buildIndexes();

        $response = $this->actingAs($user)->getJson('/_search/name/needle')->assertOk();
        $this->assertFalse($response->json('overheated'));
        $this->assertSame(['needle-dir', 'needle-visible.txt'], $this->resultNames($response->json('results')));

        // The directory being searched within is not itself a result.
        $response = $this->actingAs($user)->getJson('/_search/name/needle?path=Allowed/needle-dir')->assertOk();
        $this->assertSame([], $this->resultNames($response->json('results')));
    }

    public function test_kind_filter_finds_matches_crowded_out_by_other_kinds(): void
    {
        $user = $this->userWithRootAccess();

        for ($i = 0; $i < 80; $i++) {
            $this->addText('Dir', "needle-$i.txt", 'needle needle needle needle');
        }
        $this->addText('Dir', 'needle.jpg', 'a needle', 'image');

        $this->buildIndexes();

        $response = $this->actingAs($user)->getJson('/_search/name/needle?kind[]=image')->assertOk();
        $this->assertFalse($response->json('overheated'));
        $this->assertSame(['needle.jpg'], $this->resultNames($response->json('results')));

        $response = $this->actingAs($user)->getJson('/_search/content/needle?kind[]=image')->assertOk();
        $this->assertFalse($response->json('overheated'));
        $this->assertSame(['needle.jpg'], $this->resultNames($response->json('results')));
    }

    public function test_name_search_at_root_includes_directories(): void
    {
        $user = $this->userWithRootAccess();

        $this->directoryId('Some/needle-dir');
        $this->buildIndexes();

        $response = $this->actingAs($user)->getJson('/_search/name/needle?path=/')->assertOk();
        $this->assertSame(['needle-dir'], $this->resultNames($response->json('results')));
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

    public function test_index_syncing_uses_the_single_worker_search_queue(): void
    {
        config(['scout.queue' => true]);
        Queue::fake();

        $this->addText('Dir', 'file.txt', 'some text');

        // The text is created through Eloquent (the fixture directories and file aren't), so Scout queues its sync.
        Queue::assertPushedOn('search', MakeSearchable::class);
        Queue::assertNotPushed(MakeSearchable::class, fn ($job) => $job->queue !== 'search');
    }

    public function test_index_writes_do_not_leave_a_read_transaction_open(): void
    {
        $this->addText('Dir', 'a.txt', 'some text');
        $this->addText('Dir', 'b.txt', 'some more text');

        // Update existing documents, as that takes upstream's path that reads a term ID with fetchColumn().
        IndexedFileText::all()->searchable();

        // With the indexing connection still open, nothing should stop the WAL being fully checkpointed and reset.
        // An open read transaction would pin it, letting it grow without bound.
        $index = new \PDO('sqlite:'.$this->indexPath.'/indexed_file_texts.index');
        [$busy, $log, $checkpointed] = $index->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetch(\PDO::FETCH_NUM);

        $this->assertSame(0, (int) $busy);
        $this->assertSame((int) $log, (int) $checkpointed);
    }

    /**
     * Index everything in the database, as the application does via the indexing jobs.
     */
    private function buildIndexes(): void
    {
        foreach ([IndexedDirectory::class, IndexedFile::class, IndexedFileText::class] as $class) {
            // Make sure the index exists even if there's nothing to put in it.
            $model = new $class;
            $model->searchableUsing()->initIndex($model);

            $class::all()->searchable();
        }
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

    private function directoryId(string $path): int
    {
        if (isset($this->directoryIds[$path])) {
            return $this->directoryIds[$path];
        }

        $parentId = ($path === '') ? null : $this->directoryId(str_contains($path, '/') ? dirname($path) : '');

        return $this->directoryIds[$path] = DB::table('indexed_directories')->insertGetId([
            'parent_id' => $parentId,
            'name' => basename($path),
            'path' => $path,
            'inode' => 1,
            'mtime' => 1,
        ]);
    }

    private function addFile(string $directory, string $name, string $kind = 'text'): int
    {
        return DB::table('indexed_files')->insertGetId([
            'directory_id' => $this->directoryId($directory),
            'name' => $name,
            'kind' => $kind,
            'inode' => 1,
            'mtime' => 1,
            'size' => 1,
            'mime_type' => 'text/plain',
            'oshash' => random_bytes(8),
        ]);
    }

    private function addText(string $directory, string $name, string $text, string $kind = 'text'): void
    {
        IndexedFileText::create(['file_id' => $this->addFile($directory, $name, $kind), 'text' => $text]);
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

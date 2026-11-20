<?php

namespace Tests\Feature;

use App\Auth\AccessRules;
use App\Auth\PathAccess;
use App\Models\AccessControlEntry;
use App\Models\FileFingerprint;
use App\Models\IndexedDirectory;
use App\Models\IndexedFile;
use App\Models\IndexedFileText;
use App\Models\SharedLink;
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

    private string $mediaPath;

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
        $this->assertSame(media_info_url('Inside/a.txt'), array_column($response->json('results'), 'link', 'name')['a.txt']);
    }

    public function test_content_search_only_returns_accessible_files(): void
    {
        $user = User::factory()->create();
        AccessControlEntry::create(['path' => '']);
        $this->grantAccess($user, 'Allowed');
        AccessControlEntry::create(['path' => 'Allowed/Denied']);
        $this->grantAccess($user, 'Allowed/Denied/Regranted');

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
        $this->grantAccess($admin, 'Allowed');
        $user = User::factory()->create();
        $this->grantAccess($user, 'Allowed');

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
        $this->grantAccess($user, 'Allowed');
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

        // Files link to their info page, directories to their listing.
        $links = array_column($response->json('results'), 'link', 'name');
        $this->assertSame('/Allowed/needle-dir/', $links['needle-dir']);
        $this->assertSame(media_info_url('Allowed/needle-visible.txt'), $links['needle-visible.txt']);

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
            'fingerprint_id' => FileFingerprint::findOrCreateForHash(str_repeat('0', 16))->id,
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

    public function test_a_guest_searches_only_what_is_shared_with_them(): void
    {
        $creator = $this->userWithRootAccess(['can_share' => true]);
        // The creator can't see Private, so neither can anyone through their link.
        AccessControlEntry::create(['path' => 'Shared/Private']);

        $this->addText('Shared', 'a.txt', 'a needle');
        $this->addText('Shared/Deeper', 'b.txt', 'a needle');
        $this->addText('Shared/Private', 'c.txt', 'a needle');
        $this->addText('Outside', 'd.txt', 'a needle');
        $this->addText('Other', 'e.txt', 'a needle');
        $this->addText('Other', 'f.txt', 'a needle');
        $this->buildIndexes();

        $this->openLink($creator, 'Shared', isDirectory: true);
        $this->openLink($creator, 'Other/e.txt', isDirectory: false);

        $this->assertSame(['a.txt', 'b.txt', 'e.txt'], $this->resultNames($this->getJson('/_search/content/needle')->assertOk()->json('results')));
        $this->assertSame(['a.txt', 'b.txt'], $this->resultNames($this->getJson('/_search/content/needle?path=Shared')->json('results')));
        $this->assertSame(['e.txt'], $this->resultNames($this->getJson('/_search/content/needle?path=Other')->json('results')));
        $this->assertSame([], $this->getJson('/_search/content/needle?path=Outside')->json('results'));

        // Name search, of files and directories alike.
        $this->assertSame(['a.txt', 'b.txt', 'e.txt'], $this->resultNames($this->getJson('/_search/name/txt')->json('results')));
        $this->assertSame(['Deeper'], $this->resultNames($this->getJson('/_search/name/Deeper')->json('results')));
        $this->assertSame([], $this->getJson('/_search/name/Private')->json('results'));

        // A guest sees everything through links, so nothing is marked.
        $this->assertSame([false], array_unique(array_column($this->getJson('/_search/content/needle')->json('results'), 'shared')));
    }

    public function test_signed_in_searches_cover_shared_directories_too_marked(): void
    {
        $creator = $this->userWithRootAccess(['can_share' => true]);
        $user = User::factory()->create();
        $this->grantAccess($user, 'Mine');

        $this->addText('Mine', 'a.txt', 'a needle');
        $this->addText('Shared', 'b.txt', 'a needle');
        $this->addText('Outside', 'c.txt', 'a needle');
        $this->buildIndexes();

        $this->actingAs($user);
        $this->assertSame(['a.txt'], $this->resultNames($this->getJson('/_search/content/needle')->json('results')));

        $this->openLink($creator, 'Shared', isDirectory: true);

        $results = $this->getJson('/_search/content/needle')->assertOk()->json('results');
        $this->assertSame(['a.txt' => false, 'b.txt' => true], array_column($results, 'shared', 'name'));
    }

    public function test_guest_searches_are_throttled(): void
    {
        $creator = $this->userWithRootAccess(['can_share' => true]);
        $this->buildIndexes();
        $this->openLink($creator, 'Shared', isDirectory: true);

        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/_search/name/anything')->assertOk();
        }
        $this->getJson('/_search/name/anything')->assertTooManyRequests();

        // Signed-in users aren't.
        $this->actingAs($creator);
        for ($i = 0; $i < 31; $i++) {
            $this->getJson('/_search/name/anything')->assertOk();
        }
    }

    /**
     * The search scope's SQL and PathAccess implement the same rules separately (one up front in the index query, one
     * as the check on each result), so they must agree on every directory.
     */
    public function test_the_search_scope_selects_exactly_the_directories_path_access_allows(): void
    {
        $paths = ['', 'A', 'A/B', 'A/B/C', 'A/B/C/D', 'A/E', 'A/E/F', 'AB', 'G', 'G/H', 'G/H/I', 'G/J', '42', '42/7', '42/7/3387', 'K_%'];
        foreach ($paths as $path) {
            $this->directoryId($path);
        }

        $rules = fn (array $rules) => AccessRules::fromRules(array_map(null, array_map(strval(...), array_keys($rules)), array_values($rules)));

        $cases = [
            'own rules only' => new PathAccess($rules(['A' => true, 'A/B' => false, 'A/B/C' => true, '42/7' => true])),
            'nothing' => new PathAccess(AccessRules::none()),
            'own and links' => new PathAccess(
                own: $rules(['A/E' => true]),
                linked: [
                    $rules(['' => true, 'G/H/I' => false])->limitedTo('G'),
                    $rules(['' => true, 'A/B' => false, 'A/B/C' => true])->limitedTo('A'),
                    AccessRules::forPath('42/7/file.txt'),
                ],
                linkedFilePaths: ['42/7/file.txt'],
            ),
            'guest with a root link' => new PathAccess(
                own: AccessRules::none(),
                linked: [$rules(['' => true, 'K_%' => false, '42' => false, '42/7/3387' => true])->limitedTo('')],
                isGuest: true,
            ),
        ];

        foreach ($cases as $name => $pathAccess) {
            foreach ([null, 'A', 'A/B', 'G', '42/7', 'AB'] as $pathPrefix) {
                $scope = $pathAccess->searchScope($pathPrefix);

                $query = IndexedDirectory::query();
                $scope->applyToDirectories($query, 'indexed_directories.path');
                $selected = $query->pluck('path')->map(strval(...))->sort()->values()->all();

                $expected = collect($paths)
                    ->filter(fn (string $path) => $pathPrefix === null || AccessRules::isAtOrUnder($path, $pathPrefix))
                    ->filter(fn (string $path) => $pathAccess->allows($path))
                    ->sort()->values()->all();

                $this->assertSame($expected, $selected, "$name, under ".($pathPrefix ?? 'the root'));
            }
        }
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

    /**
     * Open a link to the path as the current visitor (a guest, unless acting as a user). Links check that what they
     * point at exists, so it's made on disk too.
     */
    private function openLink(User $creator, string $path, bool $isDirectory): void
    {
        if (!isset($this->mediaPath)) {
            $this->mediaPath = $this->indexPath.'/media';
            config(['media.path' => $this->mediaPath]);
        }

        if ($isDirectory) {
            File::ensureDirectoryExists($this->mediaPath.'/'.$path);
        } else {
            File::ensureDirectoryExists(dirname($this->mediaPath.'/'.$path));
            file_put_contents($this->mediaPath.'/'.$path, '');
        }

        $link = SharedLink::create([
            'user_id' => $creator->id,
            'path' => $path,
            'is_directory' => $isDirectory,
            'expires_at' => now()->addDay(),
        ]);

        $this->get(route('shared-link.show', ['token' => $link->token], false))->assertOk();
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
            'fingerprint_id' => FileFingerprint::findOrCreateForHash(bin2hex(random_bytes(8)))->id,
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

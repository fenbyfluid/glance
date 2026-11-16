<?php

namespace Tests\Feature;

use App\Jobs\ComputePerceptualHashJob;
use App\Jobs\GenerateMediaFileFilmstripJob;
use App\Jobs\GenerateMediaFilePerceptualHashJob;
use App\Jobs\GenerateMediaFileThumbnailJob;
use App\Jobs\IndexDirectoryJob;
use App\Jobs\ProbeMediaFileInfoJob;
use App\Jobs\SearchScenesInStashBoxJob;
use App\Models\FileFingerprint;
use App\Models\IndexedFile;
use App\Models\StashScene;
use App\Utilities\Deferred;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class FileFingerprintTest extends TestCase
{
    use RefreshDatabase;

    private const array SCENE_TEXT = [
        "Some Scene\nParent Studio - Some Studio\nSomething happens.\nSome Performer (An Alias)",
        'Some Tag',
    ];

    private string $mediaPath;

    private string $indexPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mediaPath = sys_get_temp_dir().'/glance-fingerprint-'.Str::uuid();
        File::ensureDirectoryExists($this->mediaPath);

        $this->indexPath = sys_get_temp_dir().'/glance-fingerprint-index-'.Str::uuid();
        File::ensureDirectoryExists($this->indexPath);

        config([
            'media.path' => $this->mediaPath,
            'scout.queue' => false,
            'scout.tntsearch.storage' => $this->indexPath,
            // The engine connects to the source database itself on construction, which is MySQL outside of tests.
            'scout.tntsearch.driver' => 'sqlite',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->mediaPath);
        File::deleteDirectory($this->indexPath);

        parent::tearDown();
    }

    public function test_files_with_the_same_contents_share_a_fingerprint(): void
    {
        $this->fakeGenerationJobs();

        $contents = random_bytes(200_000);
        File::ensureDirectoryExists($this->mediaPath.'/Some/Dir');
        file_put_contents($this->mediaPath.'/Some/Dir/file.mp4', $contents);
        file_put_contents($this->mediaPath.'/Some/copy.mp4', $contents);
        file_put_contents($this->mediaPath.'/Some/other.mp4', random_bytes(200_000));

        dispatch(new IndexDirectoryJob('', -1));

        $files = IndexedFile::with('fingerprint')->get()->keyBy('name');

        $this->assertSame($files['file.mp4']->fingerprint_id, $files['copy.mp4']->fingerprint_id);
        $this->assertNotSame($files['file.mp4']->fingerprint_id, $files['other.mp4']->fingerprint_id);
        $this->assertSame(2, FileFingerprint::count());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $files['file.mp4']->fingerprint->oshash);
    }

    public function test_nothing_is_generated_for_files_with_nothing_in_them(): void
    {
        $this->fakeGenerationJobs();

        file_put_contents($this->mediaPath.'/empty.mp4', '');
        file_put_contents($this->mediaPath.'/zeros.mp4', str_repeat("\0", 100_000));
        file_put_contents($this->mediaPath.'/more-zeros.mp4', str_repeat("\0", 300_000));

        dispatch(new IndexDirectoryJob('', -1));

        $files = IndexedFile::with('fingerprint')->get();

        $this->assertCount(3, $files);
        $this->assertCount(1, $files->pluck('fingerprint_id')->unique());
        $this->assertTrue($files[0]->fingerprint->isBlank());
        $this->assertFalse($files[0]->requiresGeneration());

        // Asking for what's missing doesn't change that, and they aren't searched for either.
        dispatch(new IndexDirectoryJob('', -1, forceDirectoryTraversal: true, generateMissing: true));
        Bus::assertNothingDispatched();

        Http::fake();
        (new SearchScenesInStashBoxJob)->handle();
        Http::assertNothingSent();
    }

    public function test_what_we_know_survives_a_file_being_moved_and_reindexed(): void
    {
        $this->fakeGenerationJobs();

        File::ensureDirectoryExists($this->mediaPath.'/Some/Dir');
        file_put_contents($this->mediaPath.'/Some/Dir/file.mp4', random_bytes(200_000));

        dispatch(new IndexDirectoryJob('', -1));

        $original = IndexedFile::where('name', 'file.mp4')->with('fingerprint')->sole();

        // Nothing is known about the contents yet, so they need a perceptual hash to be searched for.
        Bus::assertChained([
            ProbeMediaFileInfoJob::class,
            GenerateMediaFileThumbnailJob::class,
            GenerateMediaFileFilmstripJob::class,
            GenerateMediaFilePerceptualHashJob::class,
        ]);

        $original->fingerprint->update(['phash' => bin2hex('abcdefgh')]);
        $scene = $this->searchFor(new Collection([$original->fingerprint]));

        File::ensureDirectoryExists($this->mediaPath.'/Other');
        rename($this->mediaPath.'/Some/Dir', $this->mediaPath.'/Other/Renamed');

        dispatch(new IndexDirectoryJob('', -1, forceDirectoryTraversal: true));

        // The index has no idea it's the same file, so it has a new entry.
        $this->assertDatabaseMissing('indexed_files', ['id' => $original->id]);
        $moved = IndexedFile::where('name', 'file.mp4')->with(['directory', 'fingerprint.stashScene', 'extractedText'])->sole();
        $this->assertSame('Other/Renamed', $moved->directory->path);

        // But it has the same contents, so everything we knew about them is still there.
        $this->assertSame($original->fingerprint_id, $moved->fingerprint_id);
        $this->assertSame(bin2hex('abcdefgh'), $moved->fingerprint->phash);
        $this->assertSame($scene->id, $moved->fingerprint->stashScene->id);
        $this->assertSame(self::SCENE_TEXT, $moved->extractedText->pluck('text')->all());

        Bus::assertChained([
            ProbeMediaFileInfoJob::class,
            GenerateMediaFileThumbnailJob::class,
            GenerateMediaFileFilmstripJob::class,
        ]);
    }

    public function test_changed_contents_get_a_new_fingerprint(): void
    {
        $this->fakeGenerationJobs();

        file_put_contents($this->mediaPath.'/file.mp4', random_bytes(200_000));

        dispatch(new IndexDirectoryJob('', -1));

        $file = IndexedFile::where('name', 'file.mp4')->with('fingerprint')->sole();
        $originalFingerprint = $file->fingerprint;
        $originalFingerprint->update(['phash' => bin2hex('abcdefgh')]);
        $this->searchFor(new Collection([$originalFingerprint]));
        $this->assertSame(2, $file->extractedText()->count());

        file_put_contents($this->mediaPath.'/file.mp4', random_bytes(200_001));

        // Generating what's missing has to look at the fingerprint of every file.
        dispatch(new IndexDirectoryJob('', -1, forceDirectoryTraversal: true, generateMissing: true));

        $file = IndexedFile::with(['fingerprint', 'extractedText'])->findOrFail($file->id);
        $this->assertNotSame($originalFingerprint->id, $file->fingerprint_id);
        $this->assertNull($file->fingerprint->phash);
        $this->assertNull($file->fingerprint->stash_scene_id);
        $this->assertCount(0, $file->extractedText);

        // The old contents might turn up again.
        $this->assertNotNull($originalFingerprint->fresh()->stash_scene_id);
    }

    public function test_perceptual_hash_is_stored_on_the_fingerprint(): void
    {
        $fingerprint = FileFingerprint::findOrCreateForHash(bin2hex('11111111'));
        $file = $this->makeFile($fingerprint);
        $copy = $this->makeFile($fingerprint);

        $image = imagecreatetruecolor(64, 64);
        ob_start();
        imagepng($image);
        $inputImage = new Deferred;
        $inputImage->set(ob_get_clean());

        (new ComputePerceptualHashJob($inputImage, $file))->handle();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $fingerprint->fresh()->phash);
        $this->assertFalse($copy->load('fingerprint')->kind->canPerceptualHash() && $copy->fingerprint->phash === null);
    }

    public function test_search_asks_about_each_unsearched_video_fingerprint_once(): void
    {
        $matched = $this->makeFingerprint('11111111');
        $matchedFile = $this->makeFile($matched);
        $matchedCopy = $this->makeFile($matched);

        $notFound = $this->makeFingerprint('22222222');
        $notFoundFile = $this->makeFile($notFound);

        $alreadySearched = $this->makeFingerprint('33333333', ['stash_searched_at' => now()]);
        $this->makeFile($alreadySearched);

        $notHashedYet = $this->makeFingerprint('44444444', ['phash' => null]);
        $this->makeFile($notHashedYet);

        $image = $this->makeFingerprint('55555555');
        $this->makeFile($image, 'image');

        $noFiles = $this->makeFingerprint('66666666');

        $sceneId = (string) Str::uuid();

        Http::fake([
            'stashdb.org/*' => Http::response(['data' => ['scenes' => [
                [$this->sceneResponse($sceneId)],
                [],
            ]]]),
        ]);

        (new SearchScenesInStashBoxJob)->handle();

        $searchedFor = json_decode(Http::recorded()->sole()[0]->body(), true)['variables']['fingerprints'];
        $this->assertSame([
            [
                ['algorithm' => 'OSHASH', 'hash' => bin2hex('11111111')],
                ['algorithm' => 'PHASH', 'hash' => bin2hex('abcdefgh')],
            ],
            [
                ['algorithm' => 'OSHASH', 'hash' => bin2hex('22222222')],
                ['algorithm' => 'PHASH', 'hash' => bin2hex('abcdefgh')],
            ],
        ], $searchedFor);

        $this->assertSame($sceneId, $matched->fresh()->stash_scene_id);
        $this->assertNotNull($matched->fresh()->stash_searched_at);
        $this->assertNull($notFound->fresh()->stash_scene_id);
        $this->assertNotNull($notFound->fresh()->stash_searched_at);

        foreach ([$notHashedYet, $image, $noFiles] as $skipped) {
            $this->assertNull($skipped->fresh()->stash_searched_at);
        }

        // The searchable text is written for every file with the matched contents.
        $this->assertSame(self::SCENE_TEXT, $matchedFile->extractedText()->get()->pluck('text')->all());
        $this->assertSame(self::SCENE_TEXT, $matchedCopy->extractedText()->get()->pluck('text')->all());
        $this->assertSame(0, $notFoundFile->extractedText()->count());

        // There's nothing left to ask about.
        (new SearchScenesInStashBoxJob)->handle();
        Http::assertSentCount(1);
    }

    public function test_failed_search_releases_its_claims(): void
    {
        $fingerprint = $this->makeFingerprint('11111111');
        $this->makeFile($fingerprint);

        $searchedAt = now()->subDay()->startOfSecond();
        $alreadySearched = $this->makeFingerprint('33333333', ['stash_searched_at' => $searchedAt]);
        $this->makeFile($alreadySearched);

        Http::fake(['stashdb.org/*' => Http::response('', 500)]);

        try {
            (new SearchScenesInStashBoxJob)->handle();
            $this->fail('The failed request should have thrown.');
        } catch (RequestException) {
            // Expected.
        }

        $this->assertNull($fingerprint->fresh()->stash_searched_at);
        $this->assertTrue($searchedAt->equalTo($alreadySearched->fresh()->stash_searched_at));
    }

    private function fakeGenerationJobs(): void
    {
        Bus::fake([
            ProbeMediaFileInfoJob::class,
            GenerateMediaFileThumbnailJob::class,
            GenerateMediaFileFilmstripJob::class,
            GenerateMediaFilePerceptualHashJob::class,
        ]);
    }

    private function makeFingerprint(string $hash, array $attributes = []): FileFingerprint
    {
        $fingerprint = FileFingerprint::findOrCreateForHash(bin2hex($hash));
        $fingerprint->update(['phash' => bin2hex('abcdefgh'), ...$attributes]);

        return $fingerprint;
    }

    private function makeFile(FileFingerprint $fingerprint, string $kind = 'video'): IndexedFile
    {
        $id = DB::table('indexed_files')->insertGetId([
            'name' => Str::uuid().'.mp4',
            'inode' => 1,
            'mtime' => 1,
            'size' => 1,
            'mime_type' => 'video/mp4',
            'kind' => $kind,
            'fingerprint_id' => $fingerprint->id,
        ]);

        return IndexedFile::findOrFail($id);
    }

    /**
     * Searches for the given fingerprints, finding the scene that sceneResponse() describes for all of them.
     *
     * @param  Collection<FileFingerprint>  $fingerprints
     */
    private function searchFor(Collection $fingerprints): StashScene
    {
        $sceneId = (string) Str::uuid();

        Http::fake(['stashdb.org/*' => Http::response(['data' => [
            'scenes' => $fingerprints->map(fn () => [$this->sceneResponse($sceneId)])->all(),
        ]])]);

        (new SearchScenesInStashBoxJob($fingerprints))->handle();

        return StashScene::findOrFail($sceneId);
    }

    private function sceneResponse(string $sceneId): array
    {
        return [
            'id' => $sceneId,
            'code' => null,
            'title' => 'Some Scene',
            'details' => 'Something happens.',
            'production_date' => null,
            'release_date' => '2020-01-02',
            'studio' => [
                'id' => '10000000-0000-0000-0000-000000000001',
                'name' => 'Some Studio',
                'parent' => [
                    'id' => '10000000-0000-0000-0000-000000000002',
                    'name' => 'Parent Studio',
                    'parent' => null,
                ],
            ],
            'tags' => [[
                'id' => '20000000-0000-0000-0000-000000000001',
                'name' => 'Some Tag',
                'category' => null,
            ]],
            'performers' => [[
                'as' => 'An Alias',
                'performer' => [
                    'id' => '30000000-0000-0000-0000-000000000001',
                    'name' => 'Some Performer',
                    'disambiguation' => null,
                    'birth_date' => null,
                    'gender' => null,
                ],
            ]],
            'fingerprints' => [],
        ];
    }
}

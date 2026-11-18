<?php

namespace Tests\Feature\Media;

use App\Media\FileDetails;
use App\Media\MediaInfo;
use App\Media\MediaInfo\VideoStreamInfo;
use App\Models\AccessControlEntry;
use App\Models\FileFingerprint;
use App\Models\IndexedDirectory;
use App\Models\IndexedFile;
use App\Models\IndexedFileText;
use App\Models\StashPerformer;
use App\Models\StashScene;
use App\Models\StashStudio;
use App\Models\StashTag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

class InfoPageTest extends TestCase
{
    use RefreshDatabase;

    private string $mediaPath;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.stores.apc.driver' => 'array']);
        $this->withoutVite();

        $this->mediaPath = sys_get_temp_dir().'/glance-info-'.Str::uuid();
        File::ensureDirectoryExists($this->mediaPath.'/Some/Dir');
        file_put_contents($this->mediaPath.'/Some/Dir/file.mp4', random_bytes(1536));

        config(['media.path' => $this->mediaPath]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->mediaPath);

        parent::tearDown();
    }

    #[RequiresPhpExtension('intl')]
    public function test_shows_the_files_details_and_scene(): void
    {
        $file = $this->indexFile('Some/Dir/file.mp4', [
            'media_info' => MediaInfo::fromArray([
                'formatName' => 'mov,mp4,m4a,3gp,3g2,mj2',
                'durationMs' => 3_723_400,
                'bitRate' => 2_500_000,
                'streams' => [
                    ['index' => 0, 'codecType' => 'video', 'codecName' => 'h264', 'profile' => 'High',
                        'width' => 1920, 'height' => 1080, 'frameRate' => ['n' => 30000, 'd' => 1001]],
                    ['index' => 1, 'codecType' => 'audio', 'codecName' => 'aac', 'sampleRate' => 48000,
                        'channels' => 2, 'channelLayout' => 'stereo', 'language' => 'eng'],
                ],
            ]),
        ]);

        $parentStudio = StashStudio::create(['id' => Str::uuid(), 'name' => 'Parent Studio']);
        $studio = StashStudio::create(['id' => Str::uuid(), 'name' => 'Some Studio', 'parent_id' => $parentStudio->id]);
        $scene = StashScene::create([
            'id' => Str::uuid(),
            'title' => 'Some Scene',
            'details' => 'Something happens.',
            'release_date_year' => 2020,
            'release_date_month' => 3,
            'studio_id' => $studio->id,
        ]);
        $scene->performers()->attach(StashPerformer::create(['id' => Str::uuid(), 'name' => 'Some Performer', 'gender' => 'FEMALE']), ['as' => 'An Alias']);
        $scene->performers()->attach(StashPerformer::create(['id' => Str::uuid(), 'name' => 'Another Performer', 'gender' => 'MALE']));
        $scene->tags()->attach(StashTag::create(['id' => Str::uuid(), 'name' => 'Some Tag']));
        $file->fingerprint->update(['phash' => 'abcdef0123456789', 'stash_scene_id' => $scene->id]);

        $response = $this->actingAs($this->userWithRootAccess())->get(media_info_url('Some/Dir/file.mp4'));

        $response->assertOk()
            ->assertSee('<title>Some Scene – ', false)
            ->assertSee('data-player-video', false)
            ->assertSee('type="video/mp4"', false)
            ->assertSeeInOrder(['Some Scene', 'Parent Studio – Some Studio', '2020-03', 'Something happens.',
                'Another Performer', 'Some Performer', 'as An Alias', 'Some Tag'])
            ->assertSeeInOrder(['bg-blue-100', 'Another Performer', 'bg-pink-100', 'Some Performer'], false)
            ->assertSeeInOrder(['File', '1920 × 1080', '1:02:03', '2.5 Mb/s'])
            ->assertSeeInOrder(['Video', 'h264 (High)', '29.97 fps'])
            ->assertSeeInOrder(['Audio', 'aac', 'eng', '48 kHz', 'stereo'])
            ->assertSeeInOrder(['Fingerprints', 'abcdef0123456789'])
            ->assertSee('href="'.media_url('Some/Dir/file.mp4').'"', false);
    }

    #[RequiresPhpExtension('intl')]
    public function test_shows_extracted_text_when_there_is_no_scene(): void
    {
        $file = $this->indexFile('Some/Dir/file.mp4', ['kind' => 'image', 'mime_type' => 'image/png']);
        IndexedFileText::withoutSyncingToSearch(
            fn () => $file->extractedText()->createMany([['text' => 'First chunk'], ['text' => 'Second chunk']]),
        );

        $response = $this->actingAs($this->userWithRootAccess())->get(media_info_url('Some/Dir/file.mp4'));

        $response->assertOk()
            ->assertSee('Extracted text')
            ->assertSeeInOrder(['First chunk', 'Second chunk'])
            ->assertDontSee('Scene');
    }

    #[RequiresPhpExtension('intl')]
    public function test_falls_back_to_the_filesystem_for_files_that_are_not_indexed(): void
    {
        $response = $this->actingAs($this->userWithRootAccess())->get(media_info_url('Some/Dir/file.mp4'));

        $response->assertOk()
            ->assertSee('file.mp4')
            ->assertSee('1.5 KB')
            ->assertSee("hasn't been indexed yet", false);
    }

    #[RequiresPhpExtension('intl')]
    public function test_ignores_an_outdated_index_entry(): void
    {
        $this->indexFile('Some/Dir/file.mp4', ['size' => 1, 'thumbnail' => (string) Str::uuid()]);

        $response = $this->actingAs($this->userWithRootAccess())->get(media_info_url('Some/Dir/file.mp4'));

        $response->assertOk()
            ->assertDontSee('poster=', false)
            ->assertSee("hasn't been indexed yet", false);
    }

    public function test_requires_access_to_the_file(): void
    {
        AccessControlEntry::create(['path' => 'Some/Dir']);

        $this->actingAs($this->userWithRootAccess())
            ->get(media_info_url('Some/Dir/file.mp4'))
            ->assertNotFound();
    }

    public function test_is_only_for_files_that_exist(): void
    {
        $this->actingAs($this->userWithRootAccess());

        $this->get(media_info_url('Some/Dir/'))->assertNotFound();
        $this->get(media_info_url('Some/Dir/missing.mp4'))->assertNotFound();
    }

    public function test_redirects_to_the_canonical_url(): void
    {
        $this->actingAs($this->userWithRootAccess())
            ->get($this->withTrailingSlash(media_info_url('Some/Dir/file.mp4').'/'))
            ->assertRedirect(media_info_url('Some/Dir/file.mp4').'?_=1');
    }

    public function test_listing_links_files_to_their_info_page(): void
    {
        $this->indexFile('Some/Dir/file.mp4');

        $this->actingAs($this->userWithRootAccess())
            ->get($this->withTrailingSlash(media_url('Some/Dir/')))
            ->assertOk()
            ->assertSee('data-info-url="'.media_info_url('Some/Dir/file.mp4').'"', false);
    }

    #[RequiresPhpExtension('intl')]
    public function test_orders_streams_by_type(): void
    {
        $file = (new IndexedFile([
            'kind' => 'video',
            'mime_type' => 'video/x-matroska',
            'media_info' => MediaInfo::fromArray([
                'streams' => [
                    ['index' => 0, 'codecType' => 'attachment', 'codecName' => 'ttf'],
                    ['index' => 1, 'codecType' => 'subtitle', 'codecName' => 'subrip'],
                    ['index' => 2, 'codecType' => 'audio', 'codecName' => 'aac', 'sampleRate' => 48000, 'channels' => 2],
                    ['index' => 3, 'codecType' => 'data', 'codecName' => 'bin_data'],
                    ['index' => 4, 'codecType' => 'audio', 'codecName' => 'opus', 'sampleRate' => 48000, 'channels' => 6],
                    ['index' => 5, 'codecType' => 'video', 'codecName' => 'h264', 'width' => 1920, 'height' => 1080],
                ],
            ]),
        ]))->setRelation('fingerprint', null);

        $sections = (new FileDetails(new \SplFileInfo($this->mediaPath.'/Some/Dir/file.mp4'), $file))->sections();

        $this->assertSame(['File', 'Video', 'Audio 1', 'Audio 2', 'Subtitle', 'Data', 'Attachment'], array_keys($sections));
        $this->assertSame(['aac', 'opus'], [$sections['Audio 1']['Codec'], $sections['Audio 2']['Codec']]);
    }

    #[RequiresPhpExtension('intl')]
    public function test_formats_rotated_dimensions_and_durations(): void
    {
        $stream = VideoStreamInfo::fromArray(['index' => 0, 'codecType' => 'video', 'width' => 1920, 'height' => 1080, 'rotation' => -90]);

        $this->assertSame('1080 × 1920', FileDetails::formatDimensions($stream));
        $this->assertSame('0:59', FileDetails::formatDuration(59_400));
        $this->assertSame('1:00', FileDetails::formatDuration(59_500));
        $this->assertSame('320 kb/s', FileDetails::formatBitRate(320_000));
        $this->assertSame('2.5 Mb/s', FileDetails::formatBitRate(2_500_000));
    }

    /**
     * The test client trims trailing slashes, including (via url()) one before a query, unless the URL is absolute
     * and has a query.
     */
    private function withTrailingSlash(string $path): string
    {
        return url('/').$path.'?_=1';
    }

    private function indexFile(string $path, array $attributes = []): IndexedFile
    {
        $parent = null;
        foreach (array_merge([''], explode('/', dirname($path))) as $i => $name) {
            $directoryPath = ($parent === null || $parent->path === '') ? $name : $parent->path.'/'.$name;
            $info = new \SplFileInfo($this->mediaPath.'/'.$directoryPath);

            $parent = IndexedDirectory::withoutSyncingToSearch(fn () => IndexedDirectory::create([
                'parent_id' => $parent?->id,
                'name' => $name,
                'path' => $directoryPath,
                'inode' => $info->getInode(),
                'mtime' => $info->getMTime(),
            ]));
        }

        $info = new \SplFileInfo($this->mediaPath.'/'.$path);

        return IndexedFile::withoutSyncingToSearch(fn () => IndexedFile::create([
            'directory_id' => $parent->id,
            'name' => $info->getFilename(),
            'inode' => $info->getInode(),
            'mtime' => $info->getMTime(),
            'size' => $info->getSize(),
            'kind' => 'video',
            'mime_type' => 'video/mp4',
            'fingerprint_id' => FileFingerprint::findOrCreateForHash('0123456789abcdef')->id,
            ...$attributes,
        ]))->load('fingerprint');
    }
}

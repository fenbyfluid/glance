<?php

namespace Tests\Feature;

use App\Models\FileFingerprint;
use App\Models\IndexedFile;
use App\Utilities\Path;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class IndexedFileImageCleanupTest extends TestCase
{
    use RefreshDatabase;

    private string $mediaPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mediaPath = sys_get_temp_dir().'/glance-image-cleanup-'.Str::uuid();
        File::ensureDirectoryExists($this->mediaPath);
        config(['media.path' => $this->mediaPath, 'scout.driver' => null]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->mediaPath);

        parent::tearDown();
    }

    public function test_updating_images_removes_existing_files_and_tolerates_missing_files(): void
    {
        $file = $this->makeFile();
        $thumbnailPath = Path::makeThumbnailPath($file->thumbnail);
        file_put_contents($thumbnailPath, 'thumbnail');

        $file->update(['thumbnail' => null, 'filmstrip' => null]);

        $this->assertFileDoesNotExist($thumbnailPath);
        $this->assertNull($file->fresh()->thumbnail);
        $this->assertNull($file->fresh()->filmstrip);
    }

    public function test_force_deleting_tolerates_missing_images_but_soft_deleting_preserves_them(): void
    {
        $file = $this->makeFile();
        $filmstripPath = Path::makeThumbnailPath($file->filmstrip);
        file_put_contents($filmstripPath, 'filmstrip');

        $file->delete();
        $this->assertFileExists($filmstripPath);

        $file->forceDelete();
        $this->assertFileDoesNotExist($filmstripPath);
        $this->assertDatabaseMissing('indexed_files', ['id' => $file->id]);
    }

    public function test_image_deletion_errors_abort_the_update(): void
    {
        $file = $this->makeFile();
        $thumbnailPath = Path::makeThumbnailPath($file->thumbnail);
        mkdir($thumbnailPath);

        try {
            $file->update(['thumbnail' => null]);
            $this->fail('Deleting a directory as a thumbnail should fail.');
        } catch (\ErrorException $exception) {
            $this->assertStringContainsString('unlink(', $exception->getMessage());
        }

        $this->assertSame($file->getOriginal('thumbnail'), $file->fresh()->thumbnail);
    }

    public function test_clean_images_deletes_filmstrips_and_repairs_missing_images(): void
    {
        $file = $this->makeFile();
        $filmstripPath = Path::makeThumbnailPath($file->filmstrip);
        file_put_contents($filmstripPath, 'filmstrip');

        $this->artisan('app:clean-images', ['--delete-filmstrip' => true])->assertSuccessful();

        $this->assertFileDoesNotExist($filmstripPath);
        $this->assertNull($file->fresh()->thumbnail);
        $this->assertNull($file->fresh()->filmstrip);
    }

    public function test_clean_images_repairs_missing_images_without_deleting_them_during_dry_run(): void
    {
        $file = $this->makeFile();

        $this->artisan('app:clean-images', ['--dry-run' => true, '--delete-filmstrip' => true])->assertSuccessful();
        $this->assertSame($file->thumbnail, $file->fresh()->thumbnail);
        $this->assertSame($file->filmstrip, $file->fresh()->filmstrip);

        $this->artisan('app:clean-images')->assertSuccessful();
        $this->assertNull($file->fresh()->thumbnail);
        $this->assertNull($file->fresh()->filmstrip);
    }

    public function test_permission_errors_prevent_filmstrip_cleanup(): void
    {
        $file = $this->makeFile();
        $filmstripPath = Path::makeThumbnailPath($file->filmstrip);
        file_put_contents($filmstripPath, 'filmstrip');
        $directory = dirname($filmstripPath);
        chmod($directory, 0555);
        clearstatcache(true, $directory);

        try {
            if (is_writable($directory)) {
                $this->markTestSkipped('Cannot simulate a permission error with this user.');
            }

            try {
                $file->update(['filmstrip' => null]);
                $this->fail('Deleting a filmstrip without directory write permission should fail.');
            } catch (\ErrorException $exception) {
                $this->assertStringContainsString('unlink(', $exception->getMessage());
            }

            $this->assertSame($file->getOriginal('filmstrip'), $file->fresh()->filmstrip);
            $this->assertFileExists($filmstripPath);
        } finally {
            chmod($directory, 0755);
        }
    }

    private function makeFile(): IndexedFile
    {
        $id = DB::table('indexed_files')->insertGetId([
            'name' => Str::uuid().'.jpg',
            'inode' => 1,
            'mtime' => 1,
            'size' => 1,
            'mime_type' => 'image/jpeg',
            'kind' => 'image',
            'fingerprint_id' => FileFingerprint::findOrCreateForHash(bin2hex('12345678'))->id,
            'thumbnail' => (string) Str::uuid(),
            'filmstrip' => (string) Str::uuid(),
        ]);

        return IndexedFile::findOrFail($id);
    }
}

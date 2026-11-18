<?php

namespace Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Jcupitt\Vips\Image;
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
        $user = $this->userWithRootAccess();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
    }

    public function test_media_index_with_valid_path(): void
    {
        $user = $this->userWithRootAccess();

        $response = $this->actingAs($user)->get(route('dashboard', ['path' => 'valid']).'/?page=1');

        $response->assertOk();
    }

    public function test_media_index_with_invalid_path(): void
    {
        $user = $this->userWithRootAccess();

        $response = $this->actingAs($user)->get(route('dashboard', ['path' => 'invalid']));

        $response->assertNotFound();
    }

    public function test_media_index_with_valid_subpath(): void
    {
        $user = $this->userWithRootAccess();

        $response = $this->actingAs($user)->get(route('dashboard', ['path' => 'valid/child']).'/?page=1');

        $response->assertOk();
    }

    public function test_media_url_encodes_directory_path_once(): void
    {
        $this->assertSame(
            '/Sample%20Directory%20-%20123%20%28Test%20Copy%29/',
            media_url('Sample Directory - 123 (Test Copy)/'),
        );
    }

    public function test_ocr_debug_response_negotiates_html_and_png(): void
    {
        $imagePath = tempnam(sys_get_temp_dir(), 'ocr-debug-');

        try {
            file_put_contents($imagePath, Image::black(100, 100)->newFromImage([0, 0, 0])->pngsave_buffer());
            config(['media.path' => dirname($imagePath)]);
            Process::preventStrayProcesses();
            Process::fake(fn () => "level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext\n4\t1\t1\t1\t1\t0\t10\t10\t60\t20\t-1\t\n5\t1\t1\t1\t1\t1\t10\t10\t60\t20\t99\tHello\n");

            $this->actingAs($this->userWithRootAccess(['is_admin' => true]));
            $url = media_url(basename($imagePath)).'?_debug_ocr=1';

            $image = $this->get($url, ['Accept' => 'image/avif,image/webp,image/*,*/*;q=0.8']);
            $image->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('Vary', 'Accept');
            $this->assertSame('image/png', getimagesizefromstring($image->getContent())['mime']);

            foreach (['text/html,application/xhtml+xml,image/webp,*/*;q=0.8', '*/*'] as $accept) {
                $html = $this->get($url, ['Accept' => $accept]);
                $html->assertOk()->assertHeader('Vary', 'Accept');
                $html->assertSee('data:image/png;base64,', false)->assertSee('alt="Hello"', false);
            }
        } finally {
            unlink($imagePath);
        }
    }

    public function test_media_index_with_invalid_subpath(): void
    {
        $user = $this->userWithRootAccess();

        $response = $this->actingAs($user)->get(route('dashboard', ['path' => 'valid/invalid']));

        $response->assertNotFound();
    }
}

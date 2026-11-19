<?php

namespace Tests\Feature;

use App\Auth\SharedLinkGrants;
use App\Models\AccessControlEntry;
use App\Models\FileFingerprint;
use App\Models\IndexedDirectory;
use App\Models\IndexedFile;
use App\Models\SharedLink;
use App\Models\User;
use App\Utilities\Path;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Jcupitt\Vips;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

class SharedLinkTest extends TestCase
{
    use RefreshDatabase;

    private string $mediaPath;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.stores.apc.driver' => 'array']);
        $this->withoutVite();

        $this->mediaPath = sys_get_temp_dir().'/glance-shared-'.Str::uuid();
        File::ensureDirectoryExists($this->mediaPath.'/Some/Dir');
        file_put_contents($this->mediaPath.'/Some/Dir/file.mp4', random_bytes(1536));
        file_put_contents($this->mediaPath.'/Some/Dir/other.mp4', random_bytes(1536));

        config(['media.path' => $this->mediaPath]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->mediaPath);

        parent::tearDown();
    }

    /**
     * Authenticate::authenticate() makes the guard it used the default for the rest of the process, which in tests
     * outlives the request, so a guest request would leave later requests looking at the shared link guard.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null): TestResponse
    {
        try {
            return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        } finally {
            $this->app['auth']->shouldUse('web');
        }
    }

    public function test_opening_a_link_records_it_and_forwards_to_the_info_page(): void
    {
        $link = $this->createLink();

        $response = $this->get($this->linkPath($link))->assertOk();

        $infoUrl = media_info_url('Some/Dir/file.mp4');
        $response->assertSee('<meta http-equiv="refresh" content="0; url='.e($infoUrl).'">', false);
        $response->assertSee('<meta property="og:title" content="file.mp4">', false);
        $response->assertSee('<meta property="og:url" content="'.e($link->url()).'">', false);
        $response->assertDontSee('og:image');
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $response->assertSessionHas(SharedLinkGrants::SESSION_KEY, [$link->id]);
    }

    public function test_link_urls_are_for_outside_the_local_network(): void
    {
        $link = $this->createLink();

        URL::setRequest(Request::create('http://glance-local.example.test:8443/'));
        $this->assertSame('http://glance.example.test:8443/_s/'.$link->token, $link->url());
    }

    public function test_unknown_and_unusable_links_are_not_found(): void
    {
        $this->get('/_s/doesnotexist')->assertNotFound();

        $link = $this->createLink();
        $link->delete();

        $this->get($this->linkPath($link))->assertNotFound()->assertSessionMissing(SharedLinkGrants::SESSION_KEY);
    }

    public function test_a_guest_gets_the_shared_file_and_nothing_else(): void
    {
        $link = $this->createLink();
        $this->get($this->linkPath($link))->assertOk();

        $this->get(media_url('Some/Dir/file.mp4'))->assertOk();

        // Everything else sends the guest to sign in, whether it exists or not.
        $this->get(media_url('Some/Dir/other.mp4'))->assertRedirect(route('login'));
        $this->get(media_url('Some/Dir/missing.mp4'))->assertRedirect(route('login'));
        $this->get(media_url('Some/Dir/'))->assertRedirect(route('login'));
        $this->get(media_url('/'))->assertRedirect(route('login'));
        $this->get(media_info_url('Some/Dir/other.mp4'))->assertRedirect(route('login'));
        $this->get(route('media.stream', ['path' => 'Some/Dir/other.mp4.m3u8']))->assertRedirect(route('login'));
        $this->get(route('search.index'))->assertRedirect(route('login'));
        $this->get(route('profile.edit'))->assertRedirect(route('login'));
        $this->getJson(route('shared-links.index', ['path' => 'Some/Dir/file.mp4']))->assertUnauthorized();
    }

    public function test_without_a_link_a_visitor_is_sent_to_sign_in(): void
    {
        $this->get(media_url('Some/Dir/file.mp4'))->assertRedirect(route('login'));
    }

    #[RequiresPhpExtension('intl')]
    public function test_a_guest_sees_the_info_page_without_the_signed_in_parts(): void
    {
        $link = $this->createLink();
        $this->get($this->linkPath($link))->assertOk();

        $this->get(media_info_url('Some/Dir/file.mp4'))
            ->assertOk()
            ->assertSee('file.mp4')
            ->assertSee(route('login'))
            ->assertDontSee('data-share-path', false)
            ->assertDontSee('id="query"', false)
            ->assertDontSee('share-dialog-title', false)
            ->assertDontSee(route('profile.edit'));
    }

    public function test_a_guest_only_gets_the_images_of_shared_files(): void
    {
        $sharedThumbnail = (string) Str::uuid();
        $otherThumbnail = (string) Str::uuid();
        $this->indexFile('Some/Dir/file.mp4', ['thumbnail' => $sharedThumbnail]);
        $this->indexFile('Some/Dir/other.mp4', ['thumbnail' => $otherThumbnail]);
        file_put_contents(Path::makeThumbnailPath($sharedThumbnail), 'image');
        file_put_contents(Path::makeThumbnailPath($otherThumbnail), 'image');

        $link = $this->createLink();
        $this->get($this->linkPath($link))->assertOk();

        $this->get(route('media.image', ['id' => $sharedThumbnail]))->assertOk();
        $this->get(route('media.image', ['id' => $otherThumbnail]))->assertNotFound();
    }

    public function test_one_session_holds_several_links(): void
    {
        $first = $this->createLink();
        $second = $this->createLink(['path' => 'Some/Dir/other.mp4']);

        $this->get($this->linkPath($first))->assertOk();
        $this->get($this->linkPath($second))->assertOk();

        $this->get(media_url('Some/Dir/file.mp4'))->assertOk();
        $this->get(media_url('Some/Dir/other.mp4'))->assertOk();
    }

    /**
     * @return array<string, array{\Closure(SharedLink, self): void, string, bool}>
     */
    public static function stoppedLinks(): array
    {
        return [
            'expired' => [fn (SharedLink $link, self $test) => $test->travel(8)->days(), 'Expired', false],
            'revoked' => [fn (SharedLink $link) => $link->delete(), 'Revoked', false],
            'creator lost access' => [fn (SharedLink $link) => AccessControlEntry::where('path', '')->first()->users()->detach($link->user), 'Creator no longer has access to the file', true],
            'creator deleted' => [fn (SharedLink $link) => $link->user->forceFill(['username' => null, 'password' => null])->save(), 'Creator\'s account is deleted or not activated', true],
            'creator lost the permission to share' => [fn (SharedLink $link) => $link->user->update(['can_share' => false]), 'Creator isn\'t allowed to create public links', true],
            'file moved' => [fn (SharedLink $link, self $test) => rename($test->mediaPath.'/Some/Dir/file.mp4', $test->mediaPath.'/Some/Dir/moved.mp4'), 'File has been moved or deleted', true],
        ];
    }

    #[DataProvider('stoppedLinks')]
    public function test_a_link_stops_working(\Closure $stop, string $reason, bool $stillListed): void
    {
        $link = $this->createLink();
        $this->get($this->linkPath($link))->assertOk();
        $this->get(media_url('Some/Dir/file.mp4'))->assertOk();

        $stop($link->fresh(), $this);

        $this->get(media_url('Some/Dir/file.mp4'))->assertRedirect(route('login'));
        $this->get($this->linkPath($link))->assertNotFound();

        $link = SharedLink::withTrashed()->with('user')->find($link->id);
        $this->assertFalse($link->isUsable());
        $this->assertSame($reason, $link->getUnusableReason());

        // Links that haven't expired or been revoked are still listed for admins, saying why they aren't working.
        $adminList = $this->actingAs($this->userWithRootAccess(['is_admin' => true]))->get(route('admin.links.index'))->assertOk();
        if ($stillListed) {
            $adminList->assertSee(e('Not working: '.$reason), false);
        } else {
            $adminList->assertDontSee('Not working', false);
        }
    }

    public function test_working_links_are_listed_without_a_warning(): void
    {
        $this->createLink();

        $this->actingAs($this->userWithRootAccess(['is_admin' => true]))
            ->get(route('admin.links.index'))
            ->assertOk()
            ->assertSee('file.mp4')
            ->assertDontSee('Not working');
    }

    public function test_a_signed_in_user_without_access_gets_in_through_a_link(): void
    {
        $user = User::factory()->canShare()->create();
        $this->actingAs($user);

        $this->get(media_url('Some/Dir/file.mp4'))->assertNotFound();

        $link = $this->createLink();
        $this->get($this->linkPath($link))->assertOk();

        $this->get(media_url('Some/Dir/file.mp4'))->assertOk();
        $this->get(media_url('Some/Dir/other.mp4'))->assertNotFound();

        // They can't pass it on with links of their own.
        $this->postJson(route('shared-links.store'), ['path' => 'Some/Dir/file.mp4', 'expires_in' => 7])->assertNotFound();
    }

    public function test_creating_a_link_needs_access_to_the_file(): void
    {
        $this->actingAs(User::factory()->canShare()->create());
        $this->postJson(route('shared-links.store'), ['path' => 'Some/Dir/file.mp4', 'expires_in' => 7])->assertNotFound();

        $user = $this->userWithRootAccess(['can_share' => true]);
        $this->actingAs($user);

        $this->postJson(route('shared-links.store'), ['path' => 'Some/Dir/missing.mp4', 'expires_in' => 7])->assertNotFound();
        $this->postJson(route('shared-links.store'), ['path' => 'Some/Dir/', 'expires_in' => 7])->assertNotFound();
        $this->postJson(route('shared-links.store'), ['path' => 'Some/Dir/file.mp4', 'expires_in' => 2])->assertUnprocessable();

        $this->freezeSecond();
        $response = $this->postJson(route('shared-links.store'), [
            'path' => 'Some/./Dir/file.mp4',
            'note' => 'For someone',
            'expires_in' => 30,
        ])->assertCreated();

        $link = SharedLink::sole();
        $this->assertSame('Some/Dir/file.mp4', $link->path);
        $this->assertSame('For someone', $link->note);
        $this->assertTrue($link->user->is($user));
        $this->assertTrue($link->expires_at->equalTo(now()->addDays(30)));
        $this->assertSame(22, strlen($link->token));
        $response->assertJsonPath('link.url', $link->url())->assertJsonPath('link.creator', null);
    }

    public function test_creating_a_link_needs_the_permission_to_share(): void
    {
        // Admins included: they grant it to themselves.
        foreach ([$this->userWithRootAccess(), $this->userWithRootAccess(['is_admin' => true])] as $user) {
            $this->actingAs($user);

            $this->postJson(route('shared-links.store'), ['path' => 'Some/Dir/file.mp4', 'expires_in' => 7])->assertForbidden();

            // The share dialog only has the link for people with access, and there's no list of their links.
            $this->get(route('profile.edit'))
                ->assertOk()
                ->assertSee('share-dialog-title', false)
                ->assertDontSee('share-dialog-note', false)
                ->assertDontSee(route('profile.links'));
            $this->get(route('profile.links'))->assertForbidden();
        }

        $this->assertSame(0, SharedLink::count());

        $this->actingAs($this->userWithRootAccess(['can_share' => true]))
            ->get(route('profile.edit'))
            ->assertSee('share-dialog-note', false)
            ->assertSee(route('profile.links'));
    }

    public function test_admins_grant_the_permission_to_share(): void
    {
        $user = User::factory()->create(['note' => 'Someone']);

        $this->actingAs($this->userWithRootAccess(['is_admin' => true]));

        $this->get(route('admin.users.edit', ['user' => $user]))->assertOk()->assertSee('name="can_share"', false);

        $this->patch(route('admin.users.update', ['user' => $user]), ['note' => 'Someone', 'can_share' => '1'])->assertRedirect();
        $this->assertTrue($user->fresh()->can_share);

        $this->patch(route('admin.users.update', ['user' => $user]), ['note' => 'Someone'])->assertRedirect();
        $this->assertFalse($user->fresh()->can_share);

        $this->post(route('admin.users.store'), ['note' => 'Someone else', 'can_share' => '1'])->assertRedirect();
        $this->assertTrue(User::where('note', 'Someone else')->sole()->can_share);
    }

    public function test_the_dialog_lists_your_own_links_and_admins_see_everyones(): void
    {
        $user = $this->userWithRootAccess(['note' => 'Someone']);
        $admin = $this->userWithRootAccess(['is_admin' => true]);

        $own = $this->createLink(['user_id' => $user->id]);
        $other = $this->createLink(['user_id' => $admin->id]);
        $this->createLink(['user_id' => $user->id, 'path' => 'Some/Dir/other.mp4']);
        $this->createLink(['user_id' => $user->id, 'expires_at' => now()->subMinute()]);
        $this->createLink(['user_id' => $user->id])->delete();

        $this->actingAs($user)
            ->getJson(route('shared-links.index', ['path' => 'Some/Dir/file.mp4']))
            ->assertOk()
            ->assertJsonCount(1, 'links')
            ->assertJsonPath('links.0.id', $own->id);

        $this->actingAs($admin)
            ->getJson(route('shared-links.index', ['path' => 'Some/Dir/file.mp4']))
            ->assertOk()
            ->assertJsonCount(2, 'links')
            ->assertJsonPath('links.*.id', fn (array $ids) => collect($ids)->sort()->values()->all() === [$own->id, $other->id])
            ->assertJsonFragment(['id' => $own->id, 'creator' => 'Someone']);
    }

    public function test_only_the_creator_or_an_admin_can_change_a_link(): void
    {
        $link = $this->createLink();

        $this->actingAs($this->userWithRootAccess());
        $this->patchJson(route('shared-links.update', ['sharedLink' => $link]), ['note' => 'Mine now'])->assertForbidden();
        $this->deleteJson(route('shared-links.destroy', ['sharedLink' => $link]))->assertForbidden();

        $this->actingAs($link->user);
        $this->patchJson(route('shared-links.update', ['sharedLink' => $link]), ['note' => 'Updated'])
            ->assertOk()
            ->assertJsonPath('link.note', 'Updated');
        $this->deleteJson(route('shared-links.destroy', ['sharedLink' => $link]))->assertNoContent();

        $this->assertSoftDeleted($link);
        $this->deleteJson(route('shared-links.destroy', ['sharedLink' => $link]))->assertNotFound();

        $adminsLink = $this->createLink();
        $this->actingAs($this->userWithRootAccess(['is_admin' => true]));
        $this->delete(route('shared-links.destroy', ['sharedLink' => $adminsLink]))->assertRedirect();
        $this->assertSoftDeleted($adminsLink);
    }

    public function test_the_lists_show_active_links(): void
    {
        $user = $this->userWithRootAccess(['can_share' => true]);
        $active = $this->createLink(['user_id' => $user->id, 'note' => 'Active note']);
        $this->createLink(['user_id' => $user->id, 'note' => 'Expired note', 'expires_at' => now()->subMinute()]);
        $this->createLink(['note' => 'Someone else\'s note']);

        $this->actingAs($user)->get(route('profile.links'))
            ->assertOk()
            ->assertSee($active->url())
            ->assertSee('Active note')
            ->assertDontSee('Expired note')
            ->assertDontSee('Someone else\'s note');

        $this->get(route('admin.links.index'))->assertForbidden();

        $this->actingAs($this->userWithRootAccess(['is_admin' => true]))->get(route('admin.links.index'))
            ->assertOk()
            ->assertSee('Active note')
            ->assertSee('Someone else\'s note')
            ->assertDontSee('Expired note');
    }

    public function test_revoked_and_expired_links_are_pruned_after_a_while(): void
    {
        $active = $this->createLink();
        $recentlyRevoked = $this->createLink();
        $recentlyRevoked->delete();
        $recentlyExpired = $this->createLink(['expires_at' => now()->subDays(29)]);

        $longRevoked = $this->createLink();
        $this->travel(-31)->days();
        $longRevoked->delete();
        $this->travelBack();
        $longExpired = $this->createLink(['expires_at' => now()->subDays(31)]);

        $this->artisan('model:prune', ['--model' => [SharedLink::class]])->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            [$active->id, $recentlyRevoked->id, $recentlyExpired->id],
            SharedLink::withTrashed()->pluck('id')->all());
        $this->assertNotContains($longRevoked->id, SharedLink::withTrashed()->pluck('id'));
        $this->assertNotContains($longExpired->id, SharedLink::withTrashed()->pluck('id'));
    }

    public function test_the_preview_image_is_a_jpeg_of_the_thumbnail(): void
    {
        try {
            $thumbnail = (string) Str::uuid();
            Vips\Image::black(32, 18)->writeToFile(Path::makeThumbnailPath($thumbnail));
        } catch (\Throwable $exception) {
            $this->markTestSkipped('libvips with AVIF support is needed: '.$exception->getMessage());
        }

        $link = $this->createLink();
        $this->get(route('shared-link.preview', ['token' => $link->token]))->assertNotFound();

        $this->indexFile('Some/Dir/file.mp4', ['thumbnail' => $thumbnail]);

        $this->get($this->linkPath($link))->assertSee('<meta property="og:image" content="'.e($link->previewUrl()).'">', false);

        $response = $this->get(route('shared-link.preview', ['token' => $link->token]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringStartsWith("\xFF\xD8", $response->getContent());

        $link->delete();
        $this->get(route('shared-link.preview', ['token' => $link->token]))->assertNotFound();
    }

    private function createLink(array $attributes = []): SharedLink
    {
        if (!isset($attributes['user_id'])) {
            $attributes['user_id'] = $this->userWithRootAccess(['can_share' => true])->id;
        }

        return SharedLink::create([
            'path' => 'Some/Dir/file.mp4',
            'expires_at' => now()->addDays(7),
            ...$attributes,
        ])->load('user');
    }

    private function linkPath(SharedLink $link): string
    {
        return route('shared-link.show', ['token' => $link->token], false);
    }

    private function indexFile(string $path, array $attributes = []): IndexedFile
    {
        $parent = null;
        foreach (array_merge([''], explode('/', dirname($path))) as $name) {
            $directoryPath = ($parent === null || $parent->path === '') ? $name : $parent->path.'/'.$name;
            $info = new \SplFileInfo($this->mediaPath.'/'.$directoryPath);

            $parent = IndexedDirectory::withoutSyncingToSearch(fn () => IndexedDirectory::firstOrCreate(['path' => $directoryPath], [
                'parent_id' => $parent?->id,
                'name' => $name,
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
            'fingerprint_id' => FileFingerprint::findOrCreateForHash(substr(hash('xxh128', $path), 0, 16))->id,
            ...$attributes,
        ]));
    }
}

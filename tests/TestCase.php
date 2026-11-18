<?php

namespace Tests;

use App\Http\Middleware\RedirectLocalAccess;
use App\Models\AccessControlEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    private string $searchIndexPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(RedirectLocalAccess::class);
        Http::preventStrayRequests();

        // The search indexes are SQLite files outside the database, so a model synced to search would otherwise be
        // written into the real ones in storage/.
        $this->searchIndexPath = sys_get_temp_dir().'/glance-test-index-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->searchIndexPath);
        config(['scout.tntsearch.storage' => $this->searchIndexPath]);
    }

    /**
     * Nothing is accessible without a rule granting it, so most tests want a user who can see everything.
     */
    protected function userWithRootAccess(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $this->grantAccess($user, '');

        return $user;
    }

    protected function grantAccess(User $user, string $path): void
    {
        AccessControlEntry::firstOrCreate(['path' => $path])->users()->attach($user);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->searchIndexPath);

        parent::tearDown();
    }
}

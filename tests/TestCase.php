<?php

namespace Tests;

use App\Http\Middleware\RedirectLocalAccess;
use App\Models\AccessControlEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

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

    /**
     * Authenticate::authenticate() makes the guard it used the default for the rest of the process, which in tests
     * outlives the request, so a guest request (the shared link guard) would leave later requests looking at it.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null): TestResponse
    {
        try {
            return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        } finally {
            $this->app['auth']->shouldUse('web');
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->searchIndexPath);

        parent::tearDown();
    }
}

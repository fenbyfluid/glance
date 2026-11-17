<?php

namespace Tests;

use App\Http\Middleware\RedirectLocalAccess;
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

    protected function tearDown(): void
    {
        File::deleteDirectory($this->searchIndexPath);

        parent::tearDown();
    }
}

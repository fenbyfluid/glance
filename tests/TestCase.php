<?php

namespace Tests;

use App\Http\Middleware\RedirectLocalAccess;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(RedirectLocalAccess::class);
        Http::preventStrayRequests();
    }
}

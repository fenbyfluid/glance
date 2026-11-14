<?php

namespace Tests\Feature;

use App\Http\Middleware\RedirectLocalAccess;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RedirectLocalAccessTest extends TestCase
{
    public function test_client_with_same_ip_is_redirected_to_local_host(): void
    {
        $this->withMiddleware(RedirectLocalAccess::class);
        Http::fake([
            'http://example.test/_get_remote_ip' => Http::response('192.0.2.1'),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])
            ->get('http://example.test/_get_remote_ip')
            ->assertRedirect('http://example-local.test/_get_remote_ip');
    }
}

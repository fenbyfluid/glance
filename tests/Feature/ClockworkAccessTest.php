<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClockworkAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Clockwork only registers its routes when it's enabled at boot, which phpunit.xml turns off. Stores what it records
     * in the (in-memory) test database.
     */
    private const ENVIRONMENT = [
        'CLOCKWORK_ENABLE' => 'true',
        'CLOCKWORK_STORAGE' => 'sql',
        'CLOCKWORK_STORAGE_SQL_DATABASE' => 'sqlite',
        'CLOCKWORK_TOOLBAR' => 'true',
    ];

    private array $previousEnvironment = [];

    protected function setUp(): void
    {
        foreach (self::ENVIRONMENT as $name => $value) {
            $this->previousEnvironment[$name] = getenv($name);
            putenv("{$name}={$value}");
            $_ENV[$name] = $_SERVER[$name] = $value;
        }

        parent::setUp();

        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->previousEnvironment as $name => $value) {
            if ($value === false) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                putenv("{$name}={$value}");
                $_ENV[$name] = $_SERVER[$name] = $value;
            }
        }
    }

    public static function clockworkUrls(): array
    {
        return [
            'api' => ['/__clockwork/latest'],
            'web ui' => ['/_clockwork/app'],
        ];
    }

    #[DataProvider('clockworkUrls')]
    public function test_guest_is_sent_to_login(string $url): void
    {
        $this->get($url)->assertRedirect(route('login'));
    }

    #[DataProvider('clockworkUrls')]
    public function test_non_admin_is_forbidden(string $url): void
    {
        $this->actingAs($this->userWithRootAccess())->get($url)->assertForbidden();
    }

    #[DataProvider('clockworkUrls')]
    public function test_admin_gets_in(string $url): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get($url)->assertOk();
    }

    /**
     * Clockwork never collects requests under the console, so tests can't see its headers: check what it would decide
     * by instead.
     */
    public function test_clockwork_is_disabled_for_non_admins(): void
    {
        $this->actingAs($this->userWithRootAccess())->get('/_profile')->assertOk();

        $this->assertFalse(config('clockwork.enable'));
    }

    public function test_clockwork_is_disabled_for_guests(): void
    {
        $this->get(route('login'))->assertOk();

        $this->assertFalse(config('clockwork.enable'));
    }

    public function test_clockwork_stays_enabled_for_admins(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/_profile')->assertOk();

        $this->assertTrue(config('clockwork.enable'));
    }
}

<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class InviteTest extends TestCase
{
    use RefreshDatabase;

    public function test_invite_link_logs_in(): void
    {
        $user = User::factory()->pending()->create();

        $inviteUrl = URL::temporarySignedRoute(
            'invite.link',
            now()->addMinutes(60),
            ['user' => $user]);

        $response = $this->get($inviteUrl);

        $this->assertAuthenticated();

        $response->assertRedirectToRoute('dashboard');
    }

    public function test_invite_link_does_not_work_for_activated_user(): void
    {
        $user = User::factory()->create();

        $inviteUrl = URL::temporarySignedRoute(
            'invite.link',
            now()->addMinutes(60),
            ['user' => $user]);

        $response = $this->get($inviteUrl);

        $this->assertGuest();

        $response->assertRedirectToRoute('login')
            ->assertSessionHas('expected-username', $user->username);
    }

    public function test_activation_screen_can_be_rendered(): void
    {
        $user = User::factory()->pending()->create();

        $response = $this->actingAs($user)
            ->get(route('invite'));

        $response->assertOk();
    }

    public function test_user_can_be_activated(): void
    {
        $user = User::factory()->pending()->create();

        Event::fake();

        $response = $this->actingAs($user)->post(route('invite'), [
            'username' => 'test.user',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        Event::assertDispatched(PasswordReset::class);

        $this->assertNotNull($user->fresh()->username);

        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_pending_user_cannot_visit_dashboard(): void
    {
        $user = User::factory()->pending()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertRedirectToRoute('invite');
    }

    public function test_activated_user_cannot_visit_activation(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('invite'));

        $response->assertRedirectToRoute('profile.edit');
    }
}

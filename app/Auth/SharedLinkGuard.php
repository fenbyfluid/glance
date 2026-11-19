<?php

namespace App\Auth;

use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;

/**
 * Authenticates visitors who aren't signed in as a SharedLinkGuest, when their session holds shared links that are
 * still usable. Only the routes that serve shared files list this guard (`auth:web,shared`), so a guest gets nowhere
 * else, and any of their requests for a path the links don't cover is sent to the login page (PathAccess).
 */
class SharedLinkGuard implements Guard
{
    use GuardHelpers;

    public function __construct(
        private Request $request,
    ) {}

    public function user(): ?Authenticatable
    {
        // Kept on the request rather than the guard, which outlives a request (in tests at least).
        if (!$this->request->attributes->has(SharedLinkGuest::class)) {
            $links = SharedLinkGrants::forRequest($this->request);

            $this->request->attributes->set(SharedLinkGuest::class, $links->isNotEmpty() ? SharedLinkGuest::forLinks($links) : null);
        }

        return $this->request->attributes->get(SharedLinkGuest::class);
    }

    public function validate(array $credentials = []): bool
    {
        return false;
    }

    public function setRequest(Request $request): static
    {
        $this->request = $request;

        return $this;
    }
}

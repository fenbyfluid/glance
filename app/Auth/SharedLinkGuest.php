<?php

namespace App\Auth;

use App\Models\SharedLink;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who a visitor is when they aren't signed in but have opened shared links (see SharedLinkGuard). Never saved: it only
 * exists for the request, and can see exactly the paths of its links. Everything else that asks about users treats it
 * as a user without any rules, which since access is denied by default sees nothing.
 */
class SharedLinkGuest extends User
{
    /** @var Collection<int, SharedLink> */
    private Collection $links;

    /**
     * @param  Collection<int, SharedLink>  $links  Usable links only (see SharedLinkGrants).
     */
    public static function forLinks(Collection $links): self
    {
        $guest = new self;
        $guest->setRawAttributes([
            'username' => 'Guest',
            // Not null, so the guest counts as activated (EnsureUserIsActivated).
            'password' => '',
            'note' => '',
            'is_admin' => false,
            'can_share' => false,
        ], true);
        $guest->links = $links;

        return $guest;
    }

    /**
     * @return Collection<int, SharedLink>
     */
    public function getLinks(): Collection
    {
        return $this->links;
    }

    public function getPathAccessInfo(string $path): array
    {
        return ['' => $this->links->contains(fn (SharedLink $link) => $link->path === $path)];
    }

    public function save(array $options = []): bool
    {
        throw new \LogicException('A shared link guest can\'t be saved.');
    }

    public function delete(): ?bool
    {
        throw new \LogicException('A shared link guest can\'t be deleted.');
    }
}

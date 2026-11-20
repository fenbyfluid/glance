<?php

namespace App\Auth;

use App\Models\SharedLink;
use App\Models\User;
use App\Search\SearchScope;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

/**
 * Who can see what in this request: the user's own access rules (or those of the user an admin is browsing as), plus
 * a set of rules for each shared link opened in the session. Something is accessible if any of the sets allows it.
 * Everything handling a request asks this (listings, the info page, streams, images, search), so they all agree.
 *
 * A guest (SharedLinkGuest) has no rules of its own, only its links'. Like a restricted user, it sees the way down to
 * what's shared with it, and gets sent to log in for anything else.
 */
final class PathAccess
{
    /** @var array<string, array<string|int, bool>> */
    private array $ownInfoCache = [];

    /**
     * @param  AccessRules  $own  The user's own rules (or the impersonated user's), none for a guest.
     * @param  list<AccessRules>  $linked  One set per usable shared link.
     * @param  list<string>  $linkedFilePaths  Paths of the shared files among those links, which SearchScope can't
     *                                         express (it selects directories).
     */
    public function __construct(
        private readonly AccessRules $own,
        private readonly array $linked = [],
        private readonly array $linkedFilePaths = [],
        private readonly bool $isGuest = false,
    ) {}

    public static function forRequest(Request $request): self
    {
        // Built once per request: the guard, the controllers and the views all ask.
        if (!$request->attributes->has(self::class)) {
            $request->attributes->set(self::class, self::build($request));
        }

        return $request->attributes->get(self::class);
    }

    public static function isGuestRequest(Request $request): bool
    {
        return $request->user() instanceof SharedLinkGuest;
    }

    private static function build(Request $request): self
    {
        /** @var User $user */
        $user = $request->user();
        $isGuest = $user instanceof SharedLinkGuest;

        $links = SharedLinkGrants::forRequest($request);

        return new self(
            own: $isGuest ? AccessRules::none() : AccessRules::forUser(User::getAccessControlOverrideUser($request) ?? $user),
            linked: $links->map(fn (SharedLink $link) => $link->getAccessRules())->all(),
            linkedFilePaths: $links->reject(fn (SharedLink $link) => $link->is_directory)->pluck('path')->all(),
            isGuest: $isGuest,
        );
    }

    public function isGuest(): bool
    {
        return $this->isGuest;
    }

    /**
     * The access info for a path, in the format of AccessRules::infoAt(): the union of every set's.
     *
     * @return array<string|int, bool>
     */
    public function info(string $path): array
    {
        $infos = [$this->own->infoAt($path)];
        foreach ($this->linked as $rules) {
            $infos[] = $rules->infoAt($path);
        }

        if (count($infos) === 1) {
            return $infos[0];
        }

        $hasAccess = false;
        $childNames = [];
        foreach ($infos as $info) {
            $hasAccess = $hasAccess || $info[''];
            foreach ($info as $childName => $childAccess) {
                if ($childName !== '') {
                    $childNames[strval($childName)] = true;
                }
            }
        }

        $result = ['' => $hasAccess];
        foreach (array_keys($childNames) as $childName) {
            $childName = strval($childName);

            // Visible if any set shows it, each set's own default applying where it has nothing to say.
            $childAccess = false;
            foreach ($infos as $info) {
                $childAccess = $childAccess || ($info[$childName] ?? $info['']);
            }

            if ($childAccess !== $hasAccess) {
                $result[$childName] = $childAccess;
            }
        }

        return $result;
    }

    /**
     * Whether the path itself is accessible (not just a way down to something that is).
     */
    public function allows(string $path): bool
    {
        if ($this->own->allows($path)) {
            return true;
        }

        foreach ($this->linked as $rules) {
            if ($rules->allows($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The access info for a path that's being requested, ending the request if it shouldn't be seen: a directory is
     * fine if anything in it is visible, anything else must be accessible itself.
     *
     * @return array<string|int, bool>
     *
     * @throws AuthenticationException
     */
    public function ensureVisible(string $path, bool $isDirectory): array
    {
        $info = $this->info($path);

        $isVisible = $isDirectory ? in_array(true, $info, true) : $info[''];
        if (!$isVisible) {
            $this->deny();
        }

        return $info;
    }

    /**
     * End the request for something that shouldn't be seen. A guest is sent to log in, before anything about the path
     * is looked up, so it can't tell what exists. Signed-in users get a 404 (never a 403, which would tell them).
     *
     * @throws AuthenticationException
     */
    public function deny(): never
    {
        if ($this->isGuest) {
            throw new AuthenticationException;
        }

        abort(404);
    }

    /**
     * Whether something visible at the path is so only through a shared link, not the user's own access, for marking it.
     * Guests see everything through links, so nothing is marked for them.
     *
     * @param  string|null  $childName  A child of the path as listed, or null for the path itself (as a search result).
     */
    public function isOnlyThroughLinks(string $path, ?string $childName = null): bool
    {
        if ($this->isGuest || count($this->linked) === 0) {
            return false;
        }

        if ($childName === null) {
            return !$this->own->allows($path) && $this->allows($path);
        }

        // A listing asks for every item in it.
        $ownInfo = $this->ownInfoCache[$path] ??= $this->own->infoAt($path);

        return !($ownInfo[$childName] ?? $ownInfo['']);
    }

    /**
     * What a search may return, from the same rules.
     *
     * @param  string|null  $pathPrefix  Resolved path to restrict results to, null (or the root) for no restriction.
     */
    public function searchScope(?string $pathPrefix): SearchScope
    {
        // Files shared on their own can't be selected through their directory, so are passed alongside. (Their rules
        // match no directory, so are harmless in the list.)
        return SearchScope::fromAccessRules([$this->own, ...$this->linked], $this->linkedFilePaths, $pathPrefix);
    }
}

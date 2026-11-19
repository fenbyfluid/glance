<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

/**
 * Who can see a path in this request: the user's own access rules (or those of the user an admin is browsing as), plus
 * the shared links opened in the session.
 */
final class PathAccess
{
    /**
     * The access info for a path, in the format of User::getPathAccessInfo().
     *
     * A guest (SharedLinkGuest) is sent to the login page for any path its links don't cover, before anything about
     * the path is looked up, so it can't tell what exists. Signed-in users without access get a 404 as usual.
     *
     * @return array<string|int, bool>
     *
     * @throws AuthenticationException
     */
    public static function forRequest(Request $request, string $path): array
    {
        /** @var User $user */
        $user = $request->user();

        $accessControlInfo = (User::getAccessControlOverrideUser($request) ?? $user)->getPathAccessInfo($path);

        if ($user instanceof SharedLinkGuest) {
            if (!$accessControlInfo['']) {
                throw new AuthenticationException;
            }

            return $accessControlInfo;
        }

        if (!$accessControlInfo[''] && SharedLinkGrants::grantsPath($request, $path)) {
            $accessControlInfo[''] = true;
        }

        return $accessControlInfo;
    }

    public static function isGuest(Request $request): bool
    {
        return $request->user() instanceof SharedLinkGuest;
    }
}

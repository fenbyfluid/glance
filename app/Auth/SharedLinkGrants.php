<?php

namespace App\Auth;

use App\Models\SharedLink;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The shared links opened in a session (signed in or not), which grant access to their paths for as long as they're
 * usable. Only the link IDs are kept in the session: they're looked up and checked again on every request, and the
 * ones that stopped working are dropped.
 */
final class SharedLinkGrants
{
    public const string SESSION_KEY = 'shared_links';

    // Enough for anyone opening links they've been sent, without letting the session grow without bound.
    private const int MAX_LINKS = 50;

    /**
     * @return Collection<int, SharedLink>
     */
    public static function forRequest(Request $request): Collection
    {
        if (!$request->hasSession()) {
            return collect();
        }

        // Checked once per request: the guard, the access checks and the views all ask.
        if (!$request->attributes->has(self::class)) {
            $request->attributes->set(self::class, self::resolve($request));
        }

        return $request->attributes->get(self::class);
    }

    public static function add(Request $request, SharedLink $link): void
    {
        $ids = array_values(array_diff($request->session()->get(self::SESSION_KEY, []), [$link->getKey()]));
        $ids[] = $link->getKey();

        $request->session()->put(self::SESSION_KEY, array_slice($ids, -self::MAX_LINKS));
        $request->attributes->remove(self::class);
    }

    public static function grantsPath(Request $request, string $path): bool
    {
        return self::forRequest($request)->contains(fn (SharedLink $link) => $link->path === $path);
    }

    /**
     * @return Collection<int, SharedLink>
     */
    private static function resolve(Request $request): Collection
    {
        $ids = $request->session()->get(self::SESSION_KEY, []);
        if (empty($ids)) {
            return collect();
        }

        $links = SharedLink::whereKey($ids)
            ->with('user')
            ->get()
            ->filter(fn (SharedLink $link) => $link->isUsable())
            ->values();

        $usableIds = array_values(array_intersect($ids, $links->modelKeys()));
        if ($usableIds !== $ids) {
            $request->session()->put(self::SESSION_KEY, $usableIds);
        }

        return $links;
    }
}

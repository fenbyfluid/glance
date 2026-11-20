<?php

namespace App\Auth;

use App\Models\AccessControlEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * One set of access rules: paths that are granted or denied, where the deepest rule that is a path or one of its
 * ancestors decides, and a path no rule covers is denied. A user's access control entries are one set, and so is each
 * shared link (see SharedLink::getAccessRules()). This is the only place that implements those semantics: listings,
 * the info page, search (SearchScope and its post-filter) and link checks all ask it, through PathAccess when they
 * handle a request.
 *
 * Paths are compared exactly (case-sensitively), as the media tree's filesystem is.
 * TODO: The planned finer control over what a link covers (a tree of checkboxes) would produce more rules here,
 *       rather than needing another kind of grant.
 */
final readonly class AccessRules
{
    /**
     * @param  list<array{string, bool}>  $rules  Path and whether it's granted, sorted so ancestors come first.
     */
    private function __construct(
        private array $rules,
    ) {}

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param  iterable<array{string, bool}>  $rules  Path and whether it's granted, in any order.
     */
    public static function fromRules(iterable $rules): self
    {
        $sorted = [];
        foreach ($rules as [$path, $granted]) {
            $sorted[] = [strval($path), (bool) $granted];
        }

        // Byte order puts every ancestor before its descendants, which is all the evaluation relies on.
        usort($sorted, fn (array $a, array $b) => strcmp($a[0], $b[0]));

        return new self($sorted);
    }

    /**
     * The rules from the user's access control entries: every entry is a rule, granting if the user is on it.
     */
    public static function forUser(User $user): self
    {
        if (!$user->exists) {
            return self::none();
        }

        $entries = AccessControlEntry::query()->withCount([
            'users' => function (Builder $query) use ($user) {
                $query->whereKey($user->getKey());
            },
        ])->get();

        return self::fromRules($entries->map(fn (AccessControlEntry $entry) => [strval($entry->path), $entry->users_count > 0]));
    }

    /**
     * A single granted path, which is what a link to a file is.
     */
    public static function forPath(string $path): self
    {
        return new self([[$path, true]]);
    }

    /**
     * These rules, cut down to a subtree: everything outside it is denied, and inside it everything is as before. This
     * is what a link to a directory is, from its creator's rules: deeper denies still apply, and nothing outside leaks
     * in.
     */
    public function limitedTo(string $root): self
    {
        if (!$this->allows($root)) {
            return self::none();
        }

        $rules = [[$root, true]];
        foreach ($this->rules as $rule) {
            if (self::isStrictlyUnder($rule[0], $root)) {
                $rules[] = $rule;
            }
        }

        return new self($rules);
    }

    /**
     * Whether the path itself is accessible.
     */
    public function allows(string $path): bool
    {
        $hasAccess = false;

        foreach ($this->rules as [$rulePath, $granted]) {
            if (self::isAtOrUnder($path, $rulePath)) {
                // Ancestors come first, so the last match is the deepest.
                $hasAccess = $granted;
            }
        }

        return $hasAccess;
    }

    /**
     * The access info for a path: `''` is whether the path itself is accessible, and each other key is a child whose
     * visibility differs from that. A child is visible if anything in it is accessible, so a listing shows the way down
     * to a deeper grant. Child names may be numeric, so come back as int keys: compare with strval().
     *
     * @return array<string|int, bool>
     */
    public function infoAt(string $path): array
    {
        $hasAccess = false;
        $childAccessOverrides = [];
        $pathLength = strlen($path);

        foreach ($this->rules as [$rulePath, $granted]) {
            if (self::isAtOrUnder($path, $rulePath)) {
                // This is an ancestor or ourselves, last (deepest) wins.
                $hasAccess = $granted;

                continue;
            }

            if (!self::isStrictlyUnder($rulePath, $path)) {
                continue;
            }

            // This is a descendant. Ancestors all come before descendants, so $hasAccess is final by now.
            $childPath = ($pathLength > 0) ? substr($rulePath, $pathLength + 1) : $rulePath;
            $childName = strstr($childPath, '/', true);

            if ($childName === false) {
                // A rule for the child itself.
                $childAccessOverrides[$childPath] = $granted;
            } else {
                // A rule deeper in the child: a grant there makes the child visible.
                $childAccessOverrides[$childName] = ($childAccessOverrides[$childName] ?? $hasAccess) || $granted;
            }
        }

        // Remove entries that say the same as the path itself.
        foreach ($childAccessOverrides as $childName => $childAccess) {
            if ($childAccess === $hasAccess) {
                unset($childAccessOverrides[$childName]);
            }
        }

        return ['' => $hasAccess] + $childAccessOverrides;
    }

    /**
     * Whether nothing at all is accessible.
     */
    public function isEmpty(): bool
    {
        foreach ($this->rules as [, $granted]) {
            if ($granted) {
                return false;
            }
        }

        return true;
    }

    /**
     * The rules that make a difference: those that disagree with their nearest ancestor rule (or the default deny),
     * shallowest first. Without dropping the rest, e.g. grants nested under a root grant would make SearchScope filter
     * for a user who can see everything.
     *
     * @return list<array{string, bool}>
     */
    public function getEffectiveRules(): array
    {
        $kept = [];
        $keptGrants = [];

        foreach ($this->rules as [$path, $granted]) {
            $inherited = false;
            $ancestor = $path;
            while ($ancestor !== '') {
                $slash = strrpos($ancestor, '/');
                $ancestor = ($slash === false) ? '' : substr($ancestor, 0, $slash);
                if (isset($keptGrants[$ancestor])) {
                    $inherited = $keptGrants[$ancestor];
                    break;
                }
            }

            if ($granted === $inherited) {
                continue;
            }

            $keptGrants[$path] = $granted;
            $kept[] = [$path, $granted];
        }

        return $kept;
    }

    /**
     * Whether the path is the other path or below it.
     */
    public static function isAtOrUnder(string $path, string $other): bool
    {
        return $other === '' || $path === $other || str_starts_with($path, $other.'/');
    }

    /**
     * Whether the path is below the other path (and not the path itself).
     */
    public static function isStrictlyUnder(string $path, string $other): bool
    {
        return $path !== $other && ($other === '' || str_starts_with($path, $other.'/'));
    }
}

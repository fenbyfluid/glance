<?php

namespace App\Search;

use App\Models\AccessControlEntry;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * The part of the media tree a search may return results from: the user's access control rules combined with an
 * optional "under this path" filter, expressed as SQL constraints so the allowed documents can be selected up front
 * (see FilterableSqliteEngine) rather than post-filtering the search results.
 *
 * Access semantics match SearchController's post-filter: the deepest rule that is the path or one of its ancestors
 * wins, and a path with no matching rule at all is denied. This is expressed as
 *
 *     allowed = OR over granted rules E: ( matches(E) AND NOT ( OR over any rules F under E: matches(F) ) )
 *
 * as a deeper matching rule either denies (so E must not win) or grants (and is then a witness in its own right).
 *
 * The constraints may only ever select a superset of what the post-filter allows if anything (it remains as the
 * authoritative check), as a superset only costs some wasted candidates, while a subset hides results.
 * TODO: The deny terms are the exception if the path column has a case-insensitive collation and the tree contains
 *       paths differing only by case. Not a concern on the deployment's case-sensitive filesystem, but worth
 *       revisiting (e.g. a binary collation on `path`) if we ever store paths from somewhere else.
 */
final class SearchScope
{
    /**
     * @param  list<string>  $grantedPaths  Paths of rules that grant the user access.
     * @param  list<string>  $rulePaths  Paths of all rules.
     */
    private function __construct(
        private readonly array $grantedPaths,
        private readonly array $rulePaths,
        private readonly ?string $pathPrefix,
    ) {}

    /**
     * @param  iterable<AccessControlEntry>  $entries  All entries, each with `users_count` set to whether the user is
     *                                                 granted access by it (0 or 1).
     * @param  string|null  $pathPrefix  Resolved path to restrict results to, null (or the root) for no restriction.
     */
    public static function fromAccessControlEntries(iterable $entries, ?string $pathPrefix): self
    {
        if ($pathPrefix === '') {
            $pathPrefix = null;
        }

        $grants = [];
        foreach ($entries as $entry) {
            $grants[strval($entry->path)] = $entry->users_count > 0;
        }

        // Shallowest first, so each rule's ancestors have been decided before it.
        uksort($grants, fn ($a, $b) => strlen($a) <=> strlen($b));

        $grantedPaths = [];
        $rulePaths = [];
        $keptGrants = [];

        foreach ($grants as $path => $granted) {
            $path = strval($path);

            // Drop rules that agree with the nearest ancestor rule (or the default deny), as they change nothing.
            // Without this, e.g. grants nested under a root grant would force filtering for an unrestricted user.
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

            // Rules unrelated to the requested subtree can't affect anything in it.
            if (!self::isRelevantTo($path, $pathPrefix)) {
                continue;
            }

            $rulePaths[] = $path;
            if ($granted) {
                $grantedPaths[] = $path;
            }
        }

        return new self($grantedPaths, $rulePaths, $pathPrefix);
    }

    /**
     * Nothing can possibly be in scope, so there's no point searching at all.
     */
    public function isEmpty(): bool
    {
        return count($this->grantedPaths) === 0;
    }

    /**
     * Everything is in scope, so searches don't need filtering.
     */
    public function isUnrestricted(): bool
    {
        return $this->pathPrefix === null && $this->isAccessUnrestricted();
    }

    /**
     * Constrain a query over directories to those in scope: the path filter directory or any below it, that the user
     * has access to.
     *
     * Files are in scope if their directory is: rules are only expected to control directories.
     *
     * @param  string  $pathColumn  Qualified column holding the directory's path.
     */
    public function applyToDirectories(Builder $query, string $pathColumn): void
    {
        if ($this->isEmpty()) {
            // Callers should check isEmpty() first, but never fail open.
            $query->whereRaw('1 = 0');

            return;
        }

        if ($this->pathPrefix !== null) {
            $this->whereMatches($query, $this->pathPrefix, $pathColumn);
        }

        if ($this->isAccessUnrestricted()) {
            return;
        }

        $query->where(function (Builder $query) use ($pathColumn) {
            foreach ($this->grantedPaths as $grantedPath) {
                // isAccessUnrestricted() returning false guarantees every term here has at least one constraint.
                // An empty term would be silently dropped by the query builder, which would be very wrong in an OR.
                $query->orWhere(function (Builder $query) use ($grantedPath, $pathColumn) {
                    if (!$this->coversPrefix($grantedPath)) {
                        $this->whereMatches($query, $grantedPath, $pathColumn);
                    }

                    $deeperPaths = $this->rulesUnder($grantedPath);
                    if (count($deeperPaths) > 0) {
                        $query->whereNot(function (Builder $query) use ($deeperPaths, $pathColumn) {
                            foreach ($deeperPaths as $deeperPath) {
                                $this->whereMatches($query, $deeperPath, $pathColumn, 'or');
                            }
                        });
                    }
                });
            }
        });
    }

    /**
     * Whether access rules alone allow everything within the path filter.
     */
    private function isAccessUnrestricted(): bool
    {
        foreach ($this->grantedPaths as $grantedPath) {
            if ($this->coversPrefix($grantedPath) && count($this->rulesUnder($grantedPath)) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the rule path matches everything within the path filter, i.e. it is the root, or the filter path or an
     * ancestor of it.
     */
    private function coversPrefix(string $path): bool
    {
        if ($path === '') {
            return true;
        }

        if ($this->pathPrefix === null) {
            return false;
        }

        return $this->pathPrefix === $path || str_starts_with($this->pathPrefix, $path.'/');
    }

    /**
     * @return list<string> Rule paths strictly below the given path.
     */
    private function rulesUnder(string $path): array
    {
        return array_values(array_filter($this->rulePaths, function (string $rulePath) use ($path) {
            if ($rulePath === $path) {
                return false;
            }

            return $path === '' || str_starts_with($rulePath, $path.'/');
        }));
    }

    private static function isRelevantTo(string $path, ?string $pathPrefix): bool
    {
        if ($pathPrefix === null || $path === '') {
            return true;
        }

        // The rule is the filter path, an ancestor of it, or within it.
        return $path === $pathPrefix
            || str_starts_with($pathPrefix, $path.'/')
            || str_starts_with($path, $pathPrefix.'/');
    }

    /**
     * Add a constraint matching directories at or below a (non-root) path.
     */
    private function whereMatches(Builder $query, string $path, string $pathColumn, string $boolean = 'and'): void
    {
        $query->where(function (Builder $query) use ($path, $pathColumn) {
            $query->where($pathColumn, $path)
                ->orWhereRaw($this->likeSql($query, $pathColumn), [self::escapeLike($path).'/%']);
        }, boolean: $boolean);
    }

    private function likeSql(Builder $query, string $column): string
    {
        // An explicit escape character, as MySQL/MariaDB default to backslash but SQLite has no default at all.
        return $query->getGrammar()->wrap($column)." LIKE ? ESCAPE '!'";
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}

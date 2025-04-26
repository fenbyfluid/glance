<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

// TODO: We may want to change this once we start storing path info in the database.
//       There's an argument for replacing this with the directory entry directly, but there are also good
//       reasons for keeping the ACL info separate from the real structure, e.g. pre-creating rules.

/**
 * @property string $path
 * @property Collection<User> $users
 */
class AccessControlEntry extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'path',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function scopeAffectingPath(Builder $query, string $path): void
    {
        $query->where(function (Builder $query) use ($path) {
            // Special case, at the root there is no filtering we can do.
            // The materialized rule here is "%", which is just going to match everything anyway.
            // TODO: I guess we could try and be clever and just detect the existence of descendant's rules via a CTE?
            //       But we're not expecting that many rules to worry about.
            if ($path === '') {
                return;
            }

            // Escape special characters for LIKE pattern matching.
            $escapedPath = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $path);

            // First, add any descendants.
            // This is redundant for files, but harmless.
            $query->whereLike('path', $escapedPath.'/%');

            // Then the path and any ancestors.
            $explodedEscapedPath = explode('/', $escapedPath);
            while (count($explodedEscapedPath) > 0) {
                $query->orWhereLike('path', implode('/', $explodedEscapedPath));
                array_pop($explodedEscapedPath);
            }

            // Make sure we always include the root rule.
            $query->orWhereLike('path', '');
        })->orderBy('path');
    }
}

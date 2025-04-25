<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * @property int $id
 * @property string|null $username
 * @property string|null $password
 * @property string|null $remember_token
 * @property string $note
 * @property bool $is_admin
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class User extends Model implements AuthenticatableContract, AuthorizableContract
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use Authenticatable, Authorizable, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'username',
        'password',
        'note',
        'is_admin',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    public function accessControlEntries(): BelongsToMany
    {
        return $this->belongsToMany(AccessControlEntry::class);
    }

    /**
     * @return array<string, bool>
     */
    public function getPathAccessInfo(string $path): array
    {
        $entries = AccessControlEntry::affectingPath($path)->withCount([
            'users' => function (Builder $query) {
                $query->whereKey($this->getKey());
            },
        ])->get();

        $hasAccess = true;
        $childAccessOverrides = [];
        $pathLength = strlen($path);

        // TODO: This function would really benefit from some tests.
        foreach ($entries as $entry) {
            // This will always be exactly 0 or 1
            $entryGrantsAccess = $entry->users_count > 0;

            if (strlen($entry->path) <= $pathLength) {
                // This is an ancestor or ourselves, last entry wins.
                $hasAccess = $entryGrantsAccess;

                continue;
            }

            // This is a descendant.
            $childPath = ($pathLength > 0) ? substr($entry->path, $pathLength + 1) : $entry->path;
            $childName = strstr($childPath, '/', true);

            // If $childName is false, this rule is for a direct descendant, otherwise it is for a grandchild.
            if ($childName === false) {
                $childAccessOverrides[$childPath] = $entryGrantsAccess;
            } else {
                $childAccessOverrides[$childName] = ($childAccessOverrides[$childName] ?? $hasAccess) || $entryGrantsAccess;
            }
        }

        // Remove redundant entries for sanity.
        foreach ($childAccessOverrides as $k => $childAccess) {
            if ($childAccess === $hasAccess) {
                unset($childAccessOverrides[$k]);
            }
        }

        return [
            '' => $hasAccess,
            ...$childAccessOverrides,
        ];
    }

    public function getInviteUrl(): ?string
    {
        if (isset($this->password)) {
            return null;
        }

        return URL::temporarySignedRoute(
            'invite.link',
            Carbon::now()->addDays(7),
            ['user' => $this]);
    }
}

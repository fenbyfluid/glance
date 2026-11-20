<?php

namespace App\Models;

use App\Auth\AccessRules;
use App\Http\Middleware\RedirectLocalAccess;
use Database\Factories\UserFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Http\Request;
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
 * @property bool $can_share
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class User extends Model implements AuthenticatableContract, AuthorizableContract
{
    /** @use HasFactory<UserFactory> */
    use Authenticatable, Authorizable, HasFactory, Notifiable;

    protected $attributes = [
        'note' => '',
        'is_admin' => false,
        'can_share' => false,
    ];

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
        'can_share',
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
            'can_share' => 'boolean',
        ];
    }

    public function accessControlEntries(): BelongsToMany
    {
        return $this->belongsToMany(AccessControlEntry::class);
    }

    /**
     * The user's own access to a path (see AccessRules::infoAt()). Nothing is accessible unless a rule grants it, so
     * installs need a root rule. When handling a request, ask PathAccess instead: it also covers impersonation and
     * shared links.
     *
     * @return array<string|int, bool>
     */
    public function getPathAccessInfo(string $path): array
    {
        return AccessRules::forUser($this)->infoAt($path);
    }

    public function getInviteUrl(): ?string
    {
        if (isset($this->password)) {
            return null;
        }

        $relativePath = URL::temporarySignedRoute(
            'invite.link',
            Carbon::now()->addDays(7),
            ['user' => $this],
            false);

        $request = URL::getRequest();
        $host = $request->getHost();
        $linkHost = RedirectLocalAccess::getExternalHostIfLocal($host) ?? $host;

        return URL::format(
            $request->getScheme().'://'.$linkHost.$request->getBaseUrl(),
            $relativePath);
    }

    public static function setAccessControlOverrideUser(Request $request, ?User $user): void
    {
        $request->session()->put('admin_impersonating_user', $user?->getKey());
    }

    public static function getAccessControlOverrideUser(Request $request): ?User
    {
        /** @var User $user */
        $user = $request->user();
        if (!$user->is_admin) {
            return null;
        }

        $impersonatingUserId = $request->session()->get('admin_impersonating_user');
        if ($impersonatingUserId === null) {
            return null;
        }

        return User::find($impersonatingUserId);
    }
}

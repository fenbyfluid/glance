<?php

namespace App\Models;

use App\Http\Middleware\RedirectLocalAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * A public link to a file, which lets anyone holding it in through its creator's access. Revoking it soft-deletes it.
 *
 * @property int $id
 * @property string $token
 * @property int $user_id
 * @property string $path
 * @property bool $is_directory
 * @property string|null $note
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 */
class SharedLink extends Model
{
    use MassPrunable, SoftDeletes;

    /**
     * The lifetimes offered when creating a link, in days, with their labels.
     */
    public const array EXPIRY_CHOICES = [
        1 => '1 day',
        7 => '7 days',
        30 => '30 days',
        365 => '1 year',
    ];

    public const int DEFAULT_EXPIRY_DAYS = 7;

    /**
     * How long revoked and expired links are kept (hidden) before they're deleted.
     */
    public const int PRUNE_AFTER_DAYS = 30;

    protected $fillable = [
        'user_id',
        'path',
        'is_directory',
        'note',
        'expires_at',
    ];

    protected $attributes = [
        'is_directory' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_directory' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    public static function booted(): void
    {
        static::creating(function (self $link) {
            $link->token ??= Str::random(22);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Links that are listed: not revoked, and not expired yet.
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('expires_at', '>', now());
    }

    public function prunable(): Builder
    {
        $cutoff = now()->subDays(self::PRUNE_AFTER_DAYS);

        return static::withTrashed()->where(function (Builder $query) use ($cutoff) {
            $query->where('deleted_at', '<', $cutoff)->orWhere('expires_at', '<', $cutoff);
        });
    }

    /**
     * Whether the link lets anyone in right now. This is checked on every request that relies on it, so a link stops
     * working as soon as it's revoked or expires, its creator loses access (or their account, or the permission to
     * share), or the file is moved.
     */
    public function isUsable(): bool
    {
        return $this->getUnusableReason() === null;
    }

    /**
     * Why the link doesn't let anyone in right now (see isUsable()), for showing in the lists, or null if it does.
     */
    public function getUnusableReason(): ?string
    {
        if ($this->trashed()) {
            return 'Revoked';
        }

        if (!$this->expires_at->isFuture()) {
            return 'Expired';
        }

        // TODO: Directory links are planned (the guest user would get a rule on the directory, limited by the
        //       creator's own descendant rules), but nothing creates them yet.
        if ($this->is_directory) {
            return 'Directory links aren\'t supported yet';
        }

        // Deleting an account clears its password (ProfileController::destroy()), as does a pending invite.
        if ($this->user->password === null) {
            return 'Creator\'s account is deleted or not activated';
        }

        if (!$this->user->can_share) {
            return 'Creator isn\'t allowed to create public links';
        }

        if (!$this->user->getPathAccessInfo($this->path)['']) {
            return 'Creator no longer has access to the file';
        }

        if (!is_file(config('media.path').'/'.$this->path)) {
            return 'File has been moved or deleted';
        }

        return null;
    }

    /**
     * The link as reached from outside, like User::getInviteUrl(): on the local network we're on a `-local` hostname
     * that won't resolve for anyone else.
     */
    public function url(): string
    {
        return self::externalUrl(route('shared-link.show', ['token' => $this->token], false));
    }

    /**
     * The link's preview image (for Open Graph), also as reached from outside.
     */
    public function previewUrl(): string
    {
        return self::externalUrl(route('shared-link.preview', ['token' => $this->token], false));
    }

    private static function externalUrl(string $relativePath): string
    {
        // With the port, when it isn't the scheme's default.
        $request = URL::getRequest();
        $host = $request->getHttpHost();
        $linkHost = RedirectLocalAccess::getExternalHostIfLocal($host) ?? $host;

        return URL::format($request->getScheme().'://'.$linkHost.$request->getBaseUrl(), $relativePath);
    }
}

<?php

namespace App\Models;

use App\Casts\BinaryToHex;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Everything we know about a file purely from its contents, identified by OSHash. Fingerprints outlive the files
 * that have them, so what we know follows the contents through moves, copies, and the index deleting and recreating
 * a file's entry.
 *
 * TODO: Nothing removes fingerprints that no longer have any files, so every version of a file that changes leaves
 *       one behind. They're small, but we could prune old ones that we don't know anything expensive about.
 */
class FileFingerprint extends Model
{
    protected $fillable = [
        'oshash',
        'phash',
        'stash_scene_id',
        'stash_searched_at',
    ];

    public function files(): HasMany
    {
        return $this->hasMany(IndexedFile::class, 'fingerprint_id');
    }

    public function stashScene(): BelongsTo
    {
        return $this->belongsTo(StashScene::class);
    }

    protected function casts(): array
    {
        return [
            'oshash' => BinaryToHex::withLength(8),
            'phash' => BinaryToHex::withLength(8),
            'stash_searched_at' => 'datetime',
        ];
    }

    /**
     * @param  string  $osHash  As hex.
     */
    public static function findOrCreateForHash(string $osHash): self
    {
        // Query conditions aren't cast, so have to be given the stored binary value.
        $find = fn () => self::where('oshash', '=', hex2bin($osHash))->first();

        try {
            return $find() ?? self::create(['oshash' => $osHash]);
        } catch (UniqueConstraintViolationException) {
            // Another job indexing a file with the same contents got there first.
            return $find();
        }
    }
}

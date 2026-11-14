<?php

namespace App\Models;

use App\Utilities\ScopeHavingAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;
use TeamTNT\TNTSearch\Tokenizer\NGramTokenizer;

class IndexedDirectory extends Model
{
    use ScopeHavingAccess, Searchable, SoftDeletes;

    protected $fillable = [
        'parent_id',
        'name',
        'path',
        'inode',
        'mtime',
    ];

    public function shouldBeSearchable(): true
    {
        // TODO: Exclude directories internal to plugin integrations from search (e.g. Tumblr/media)
        return true;
    }

    public function searchIndexShouldBeUpdated(): bool
    {
        return $this->wasChanged('name') || !$this->shouldBeSearchable();
    }

    public function toSearchableArray(): array
    {
        $tokenizer = new NGramTokenizer(3, 3);

        return [
            'id' => $this->id,
            'name' => implode(' ', $tokenizer->tokenize($this->name)),
        ];
    }

    public function syncWithSearchUsingQueue(): string
    {
        return 'index';
    }

    public function parent(): HasOne
    {
        return $this->hasOne(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(IndexedFile::class, 'directory_id');
    }

    public function isIndexOutdated(\SplFileInfo $info): bool
    {
        return ($info->getMTime() != $this->mtime) ||
            ($info->getInode() != $this->inode);
    }

    public function updateWithInfo(\SplFileInfo $info): bool
    {
        // TODO: We probably need some logic here to handle updating path and name on moves too.
        return $this->update([
            'inode' => $info->getInode(),
            'mtime' => $info->getMTime(),
        ]);
    }

    public static function booted(): void
    {
        // TODO: We should have another listener here that updates children on path changes.

        static::deleting(function (self $directory) {
            foreach ($directory->files()->withTrashed()->lazyById() as $file) {
                if ($directory->forceDeleting) {
                    $file->forceDelete();
                } elseif (!$file->trashed()) {
                    $file->delete();
                }
            }

            foreach ($directory->children()->withTrashed()->with(['children', 'files'])->lazyById() as $child) {
                if ($directory->forceDeleting) {
                    $child->forceDelete();
                } elseif (!$child->trashed()) {
                    $child->delete();
                }
            }
        });
    }
}

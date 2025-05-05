<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class IndexedDirectory extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'parent_id',
        'name',
        'path',
        'inode',
        'mtime',
    ];

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
            foreach ($directory->files()->withTrashed()->lazy() as $file) {
                if ($directory->forceDeleting) {
                    $file->forceDelete();
                } elseif (!$file->trashed()) {
                    $file->delete();
                }
            }

            foreach ($directory->children()->withTrashed()->with(['children', 'files'])->lazy() as $child) {
                if ($directory->forceDeleting) {
                    $child->forceDelete();
                } elseif (!$child->trashed()) {
                    $child->delete();
                }
            }
        });
    }
}

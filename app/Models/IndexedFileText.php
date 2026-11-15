<?php

namespace App\Models;

use App\Casts\Compressed;
use App\Search\FilterableSqliteEngine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection as BaseCollection;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Searchable;

class IndexedFileText extends Model
{
    use Searchable, SoftDeletes;

    protected $fillable = [
        'id',
        'file_id',
        'text',
    ];

    public function searchableUsing()
    {
        return app(EngineManager::class)->engine('tntsearch_stemmed');
    }

    public function searchIndexShouldBeUpdated(): bool
    {
        return $this->wasRecentlyCreated || $this->wasChanged('text');
    }

    public function makeSearchableUsing(BaseCollection $models): BaseCollection
    {
        // toSearchableArray() needs the file, and lazy loading is disabled. This loads it onto the same instances.
        (new Collection($models->all()))->loadMissing('file');

        return $models;
    }

    public function toSearchableArray(): array
    {
        // TODO: Texts aren't reindexed when their file's directory or kind changes, so these can go stale until the
        //       text is next extracted. Moves aren't currently a concern, and kinds rarely change.
        $file = $this->relationLoaded('file') ? $this->file : $this->file()->first();

        return [
            'id' => $this->id,
            'text' => $this->text,
            // Stored alongside the index for filtering searches, see FilterableSqliteEngine.
            FilterableSqliteEngine::META_DIRECTORY_ID => $file?->directory_id,
            FilterableSqliteEngine::META_KIND => $file?->kind?->value,
        ];
    }

    public function syncWithSearchUsingQueue(): string
    {
        return 'search';
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(IndexedFile::class, 'file_id');
    }

    protected function casts(): array
    {
        return [
            'text' => Compressed::class,
        ];
    }
}

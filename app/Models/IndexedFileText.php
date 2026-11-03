<?php

namespace App\Models;

use App\Casts\Compressed;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
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
        return $this->wasChanged('text');
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'text' => $this->text,
        ];
    }

    public function syncWithSearchUsingQueue(): string
    {
        return 'index';
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

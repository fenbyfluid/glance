<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class IndexedFileText extends Model
{
    use Searchable, SoftDeletes;

    public $incrementing = false;

    protected $fillable = [
        'id',
        'text',
    ];

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
        return $this->belongsTo(IndexedFile::class, 'id');
    }
}

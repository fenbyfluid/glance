<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class IndexedFileText extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $fillable = [
        'id',
        'text',
    ];

    public function file(): BelongsTo
    {
        return $this->belongsTo(IndexedFile::class, 'id');
    }
}

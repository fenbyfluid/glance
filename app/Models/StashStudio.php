<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StashStudio extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'parent_id',
        'name',
    ];

    public function parentStudio(): BelongsTo
    {
        return $this->belongsTo(StashStudio::class, 'parent_id');
    }

    public function childStudios(): HasMany
    {
        return $this->hasMany(StashStudio::class, 'parent_id');
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(StashScene::class, 'studio_id');
    }
}

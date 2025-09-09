<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class StashTag extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'category_id',
        'name',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(StashTagCategory::class, 'category_id');
    }

    public function scenes(): BelongsToMany
    {
        return $this->belongsToMany(StashScene::class, 'stash_scene_tag', 'tag_id', 'scene_id');
    }
}

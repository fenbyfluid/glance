<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class StashScene extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'code',
        'title',
        'details',
        'production_date_year',
        'production_date_month',
        'production_date_day',
        'release_date_year',
        'release_date_month',
        'release_date_day',
        'studio_id',
    ];

    public function studio(): BelongsTo
    {
        return $this->belongsTo(StashStudio::class, 'studio_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(StashTag::class, 'stash_scene_tag', 'scene_id', 'tag_id');
    }

    public function performers(): BelongsToMany
    {
        return $this->belongsToMany(StashPerformer::class, 'stash_scene_performer', 'scene_id', 'performer_id')
            ->withPivot('as');
    }
}

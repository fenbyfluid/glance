<?php

namespace App\Models;

use App\Enums\StashPerformerGender;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class StashPerformer extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'name',
        'disambiguation',
        'birth_date_year',
        'birth_date_month',
        'birth_date_day',
        'gender',
    ];

    protected $casts = [
        'gender' => StashPerformerGender::class,
    ];

    public function scenes(): BelongsToMany
    {
        return $this->belongsToMany(StashScene::class, 'stash_scene_performer', 'performer_id', 'scene_id')
            ->withPivot('as');
    }
}

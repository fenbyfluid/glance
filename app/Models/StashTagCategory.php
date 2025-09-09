<?php

namespace App\Models;

use App\Enums\StashTagGroup;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StashTagCategory extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'name',
        'group',
    ];

    protected $casts = [
        'group' => StashTagGroup::class,
    ];

    public function tags(): HasMany
    {
        return $this->hasMany(StashTag::class, 'category_id');
    }
}

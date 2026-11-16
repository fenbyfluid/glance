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

    /**
     * The scene's description as chunks of searchable text: the key information, then the tags.
     *
     * @return list<string>
     */
    public function textChunks(): array
    {
        $this->loadMissing(['studio.parentStudio.parentStudio', 'performers', 'tags']);

        $keyInfoChunk = [];

        if ($this->title) {
            $keyInfoChunk[] = $this->title;
        }

        if ($this->studio !== null) {
            // Only as many levels as were loaded above.
            $studioNames = [$this->studio->name];
            $parentStudio = $this->studio->parentStudio;
            if ($parentStudio !== null) {
                array_unshift($studioNames, $parentStudio->name);

                if ($parentStudio->parentStudio !== null) {
                    array_unshift($studioNames, $parentStudio->parentStudio->name);
                }
            }

            $keyInfoChunk[] = implode(' - ', $studioNames);
        }

        if ($this->details) {
            $keyInfoChunk[] = $this->details;
        }

        foreach ($this->performers as $performer) {
            $name = $performer->name;

            $appearingAs = $performer->pivot->as;
            if ($appearingAs !== null) {
                $name .= ' ('.$appearingAs.')';
            }

            $keyInfoChunk[] = $name;
        }

        $tagChunk = $this->tags->pluck('name')->all();

        return collect([$keyInfoChunk, $tagChunk])
            ->filter(fn ($chunk) => count($chunk) > 0)
            ->map(fn ($chunk) => implode("\n", $chunk))
            ->values()
            ->all();
    }
}

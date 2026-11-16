<?php

namespace App\Jobs;

use App\Enums\StashFingerprintAlgorithm;
use App\Media\MediaContentKind;
use App\Models\FileFingerprint;
use App\Models\StashPerformer;
use App\Models\StashScene;
use App\Models\StashStudio;
use App\Models\StashTag;
use App\Models\StashTagCategory;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\WithoutRelations;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SearchScenesInStashBoxJob implements ShouldQueue
{
    private const int MAX_SCENES = 40;

    use Batchable, Queueable;

    /**
     * @param  Collection<FileFingerprint>  $fingerprints  Leave empty to search for the next unsearched ones.
     */
    public function __construct(
        #[WithoutRelations]
        public Collection $fingerprints = new Collection,
    ) {}

    public function handle(): void
    {
        if ($this->fingerprints->count() > self::MAX_SCENES) {
            throw new \Exception('Too many scenes requested for batch search');
        }

        if ($this->batch()?->cancelled()) {
            return;
        }

        // The fingerprints we've marked as searched up front, to stop other jobs searching for them too.
        $claimedIds = [];

        if ($this->fingerprints->isEmpty()) {
            $this->fingerprints = Cache::lock(self::class, 60)->block(60, function () use (&$claimedIds) {
                // Blank files never get a perceptual hash, so are never searched for.
                $fingerprints = FileFingerprint::whereNull('stash_searched_at')
                    ->whereNotNull('phash')
                    ->whereHas('files', fn ($query) => $query->where('kind', MediaContentKind::Video))
                    ->take(self::MAX_SCENES)->get();

                $claimedIds = $fingerprints->modelKeys();

                FileFingerprint::whereIn('id', $claimedIds)->update([
                    'stash_searched_at' => now(),
                ]);

                return $fingerprints;
            });
        } else {
            // TODO: Sanity check the passed in fingerprints.
        }

        if ($this->fingerprints->isEmpty()) {
            return;
        }

        try {
            $this->processFingerprints($claimedIds);
        } catch (\Throwable $exception) {
            // Release the claims we didn't get to.
            FileFingerprint::whereIn('id', $claimedIds)->update([
                'stash_searched_at' => null,
            ]);

            throw $exception;
        }
    }

    /**
     * @param  list<int>  $claimedIds  Fingerprints are removed from this as their results are stored.
     */
    private function processFingerprints(array &$claimedIds): void
    {
        $query = <<<'EOT'
        query ($fingerprints: [[FingerprintQueryInput!]!]!) {
            scenes: findScenesBySceneFingerprints(fingerprints: $fingerprints) {
                id
                code
                title
                details
                production_date
                release_date

                studio {
                    id
                    name
                    parent {
                        id
                        name
                        parent {
                            id
                            name
                        }
                    }
                }

                tags {
                    id
                    name
                    category {
                        id
                        name
                        group
                    }
                }

                performers {
                    as
                    performer {
                        id
                        name
                        disambiguation
                        birth_date
                        gender
                    }
                }

                fingerprints {
                    algorithm
                    hash
                    duration
                    submissions
                    reports
                }
            }
        }
        EOT;

        $fingerprints = $this->fingerprints->map(function (FileFingerprint $fingerprint) {
            return [
                ['algorithm' => StashFingerprintAlgorithm::OsHash, 'hash' => $fingerprint->oshash],
                ['algorithm' => StashFingerprintAlgorithm::PHash, 'hash' => $fingerprint->phash],
            ];
        })->values();

        $request = [
            'query' => preg_replace('/\s+/', ' ', $query),
            'variables' => [
                'fingerprints' => $fingerprints,
            ],
        ];

        $response = Http::withHeaders([
            'ApiKey' => config('stashbox.stashdb.api_key'),
        ])->post('https://stashdb.org/graphql', $request)->throw()->object();

        if (isset($response->errors)) {
            throw new \RuntimeException('GraphQL errors: '.json_encode($response->errors));
        }

        foreach ($this->fingerprints->values() as $i => $fingerprint) {
            $this->processFingerprint($fingerprint, $response->data->scenes[$i]);

            $claimedIds = array_values(array_diff($claimedIds, [$fingerprint->getKey()]));
        }
    }

    private function processFingerprint(FileFingerprint $fingerprint, array $scenes): void
    {
        // TODO: Check through the scenes and only associate with very high-quality matches.
        $sceneData = $scenes[0] ?? null;

        if (!$sceneData) {
            $fingerprint->update([
                'stash_scene_id' => null,
                'stash_searched_at' => now(),
            ]);

            return;
        }

        DB::transaction(function () use ($fingerprint, $sceneData) {
            if ($sceneData->studio?->parent?->parent !== null) {
                StashStudio::updateOrCreate([
                    'id' => $sceneData->studio->parent->parent->id,
                ], [
                    'name' => $sceneData->studio->parent->parent->name,
                ]);
            }

            if ($sceneData->studio?->parent !== null) {
                StashStudio::updateOrCreate([
                    'id' => $sceneData->studio->parent->id,
                ], [
                    'parent_id' => $sceneData->studio->parent->parent?->id,
                    'name' => $sceneData->studio->parent->name,
                ]);
            }

            if ($sceneData->studio !== null) {
                StashStudio::updateOrCreate([
                    'id' => $sceneData->studio->id,
                ], [
                    'parent_id' => $sceneData->studio->parent?->id,
                    'name' => $sceneData->studio->name,
                ]);
            }

            $production_date = explode('-', $sceneData->production_date ?? '');
            $release_date = explode('-', $sceneData->release_date ?? '');

            $scene = StashScene::updateOrCreate([
                'id' => $sceneData->id,
            ], [
                'code' => $sceneData->code,
                'title' => $sceneData->title,
                'details' => $sceneData->details,
                'production_date_year' => $production_date[0] ?: null,
                'production_date_month' => $production_date[1] ?? null,
                'production_date_day' => $production_date[2] ?? null,
                'release_date_year' => $release_date[0] ?: null,
                'release_date_month' => $release_date[1] ?? null,
                'release_date_day' => $release_date[2] ?? null,
                'studio_id' => $sceneData->studio?->id,
            ]);

            $tags = [];
            $tagCategories = [];
            foreach ($sceneData->tags as $tag) {
                $tags[$tag->id] = [
                    'id' => $tag->id,
                    'category_id' => $tag->category?->id,
                    'name' => $tag->name,
                ];

                if ($tag->category !== null) {
                    $tagCategories[$tag->category->id] = [
                        'id' => $tag->category->id,
                        'name' => $tag->category->name,
                        'group' => $tag->category->group,
                    ];
                }
            }

            StashTagCategory::upsert(array_values($tagCategories), ['id']);

            StashTag::upsert(array_values($tags), ['id']);

            $scene->tags()->sync(array_keys($tags));

            $performers = [];
            $performerPivotData = [];
            foreach ($sceneData->performers as $performer) {
                $birth_date = explode('-', $performer->birth_date ?? '');

                $performers[$performer->performer->id] = [
                    'id' => $performer->performer->id,
                    'name' => $performer->performer->name,
                    'disambiguation' => $performer->performer->disambiguation,
                    'birth_date_year' => $birth_date[0] ?: null,
                    'birth_date_month' => $birth_date[1] ?? null,
                    'birth_date_day' => $birth_date[2] ?? null,
                    'gender' => $performer->performer->gender,
                ];

                $performerPivotData[$performer->performer->id] = [
                    'as' => $performer->as,
                ];
            }

            StashPerformer::upsert(array_values($performers), ['id']);

            $scene->performers()->sync($performerPivotData);

            $fingerprint->update([
                'stash_scene_id' => $scene->id,
                'stash_searched_at' => now(),
            ]);

            // The searchable text belongs to the files, so every file with these contents needs it.
            foreach ($fingerprint->files()->get() as $file) {
                $file->writeSceneText($scene);
            }
        });
    }
}

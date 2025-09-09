<?php

namespace App\Jobs;

use App\Enums\ExtractedTextStatus;
use App\Enums\StashFingerprintAlgorithm;
use App\Media\MediaContentKind;
use App\Models\IndexedFile;
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
use Illuminate\Support\Facades\Log;

class SearchScenesInStashBoxJob implements ShouldQueue
{
    private const int MAX_SCENES = 40;

    use Batchable, Queueable;

    /**
     * @param  Collection<IndexedFile>  $files
     */
    public function __construct(
        #[WithoutRelations]
        public Collection $files = new Collection,
    ) {}

    public function handle(): void
    {
        if ($this->files->count() > self::MAX_SCENES) {
            throw new \Exception('Too many scenes requested for batch search');
        }

        if ($this->batch()?->cancelled()) {
            return;
        }

        if ($this->files->isEmpty()) {
            $this->files = Cache::lock(self::class, 60)->block(60, function () {
                $files = IndexedFile::whereNull('stash_scene_id')
                    ->where('kind', MediaContentKind::Video)
                    ->whereNotNull('phash')
                    ->take(self::MAX_SCENES)->get();

                IndexedFile::whereIn('id', $files->pluck('id'))->update([
                    'stash_scene_id' => '00000000-0000-0000-0000-000000000000',
                ]);

                return $files;
            });
        } else {
            // TODO: Sanity check the passed in files.
        }

        if ($this->files->isEmpty()) {
            return;
        }

        try {
            $this->processFiles();
        } catch (\Throwable $exception) {
            IndexedFile::whereIn('id', $this->files->pluck('id'))->update([
                'stash_scene_id' => null,
            ]);

            throw $exception;
        }
    }

    private function processFiles(): void
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

        $fingerprints = $this->files->map(function (IndexedFile $file) {
            return [
                ['algorithm' => StashFingerprintAlgorithm::OsHash, 'hash' => $file->oshash],
                ['algorithm' => StashFingerprintAlgorithm::PHash, 'hash' => $file->phash],
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

        foreach ($this->files->values() as $i => $file) {
            $this->processFile($file, $response->data->scenes[$i]);
        }
    }

    private function processFile(IndexedFile $file, array $scenes): void
    {
        // TODO: Check through the scenes and only associate with very high-quality matches.
        $sceneData = $scenes[0] ?? null;

        if (!$sceneData) {
            $file->update([
                'stash_scene_id' => '00000000-0000-0000-0000-000000000000',
            ]);

            return;
        }

        DB::transaction(function () use ($file, $sceneData) {
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

            $file->update([
                'stash_scene_id' => $sceneData->id,
            ]);

            // Safety check in case we ever implement text extraction for videos.
            if ($file->extracted_text_status !== ExtractedTextStatus::Pending) {
                Log::warning(sprintf('File %s already has extracted text', $file->getKey()));

                return;
            }

            foreach ($file->extractedText()->get() as $extractedText) {
                $extractedText->forceDelete();
            }

            $keyInfoChunk = [];

            if ($scene->title) {
                $keyInfoChunk[] = $scene->title;
            }

            if ($sceneData->studio !== null) {
                $studioName = '';

                if ($sceneData->studio->parent?->parent !== null) {
                    $studioName .= $sceneData->studio->parent->parent->name.' - ';
                }

                if ($sceneData->studio->parent !== null) {
                    $studioName .= $sceneData->studio->name.' - ';
                }

                $studioName .= $sceneData->studio->name;

                $keyInfoChunk[] = $studioName;
            }

            if ($scene->details) {
                $keyInfoChunk[] = $scene->details;
            }

            foreach ($performers as $id => $performer) {
                $name = $performer['name'];

                $appearingAs = $performerPivotData[$id]['as'] ?? null;
                if ($appearingAs !== null) {
                    $name .= ' ('.$appearingAs.')';
                }

                $keyInfoChunk[] = $name;
            }

            $tagChunk = [];
            foreach ($scene->tags as $tag) {
                $tagChunk[] = $tag['name'];
            }

            $chunks = collect([$keyInfoChunk, $tagChunk])
                ->filter(fn ($chunk) => count($chunk) > 0)
                ->map(fn ($chunk) => [
                    'file_id' => $file->getKey(),
                    'text' => implode("\n", $chunk),
                ]);

            $file->extractedText()->createMany($chunks);
        });
    }
}

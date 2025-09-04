<?php

namespace App\Http\Controllers;

use App\Events\SearchOverheated;
use App\Media\MediaContentKind;
use App\Models\AccessControlEntry;
use App\Models\IndexedDirectory;
use App\Models\IndexedFile;
use App\Models\IndexedFileText;
use App\Models\User;
use App\Utilities\Path;
use Illuminate\Database\Eloquent;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Scout;
use TeamTNT\TNTSearch\Support\NGramTokenizer;

class SearchController extends Controller
{
    public function name(Request $request, string $query = '')
    {
        $tokenizer = new NGramTokenizer(3, 3);
        $queryTrigrams = implode(' ', $tokenizer->tokenize($query));

        [$includeKinds, $pathPrefix] = $this->getFiltersFromRequest($request);

        $queries = [];

        // If the directory type is in the requested kinds, add the directory index.
        if (in_array(MediaContentKind::Directory->value, $includeKinds, true)) {
            $queries[] = [
                IndexedDirectory::search($queryTrigrams)->query(function (Eloquent\Builder $query) use ($pathPrefix) {
                    if ($pathPrefix !== null) {
                        // Escape special characters for LIKE pattern matching.
                        $escapedPath = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $pathPrefix);

                        $query->whereLike('path', $escapedPath.'/%');
                    }
                }),
                function (IndexedDirectory $result) {
                    // Give directories a score boost, as they're much rarer.
                    $result->setAttribute('__tntSearchScore__', $result->__tntSearchScore__ * 1.5);

                    return $result->path;
                },
            ];
        }

        // If anything other than the directory type is requested, add the file index.
        if (count($includeKinds) !== 1 || $includeKinds[0] !== MediaContentKind::Directory->value) {
            $queries[] = [
                IndexedFile::search($queryTrigrams)->query(function (Eloquent\Builder $builder) use ($includeKinds) {
                    $builder->whereIn('kind', $includeKinds)->with('directory');
                }),
                function (IndexedFile $result) {
                    return $result->directory->path.'/'.$result->name;
                },
                function (IndexedFile $result) use ($pathPrefix) {
                    // TODO: If we do this a lot, add a materialised path to IndexedFile
                    return $pathPrefix === null || str_starts_with($result->__path__, $pathPrefix);
                },
            ];
        }

        $results = app('clockwork')->event('Filename Search')->run(function () use ($request, $queries) {
            return $this->performSearchWithAccessControl($request, $queries, 20);
        });

        return $results->map(function (IndexedDirectory|IndexedFile $result) {
            return match (true) {
                $result instanceof IndexedDirectory => [
                    'kind' => MediaContentKind::Directory->value,
                    'name' => $result->name,
                    'path' => $result->__path__.'/',
                    'score' => $result->__tntSearchScore__,
                ],
                $result instanceof IndexedFile => [
                    'kind' => $result->kind->value,
                    'name' => $result->name,
                    'path' => $result->__path__,
                    'thumbnail' => $result->thumbnail,
                    'score' => $result->__tntSearchScore__,
                ],
            };
        });
    }

    public function content(Request $request, string $query = '')
    {
        $results = app('clockwork')->event('Content Search')->run(function () use ($request, $query) {
            [$includeKinds, $pathPrefix] = $this->getFiltersFromRequest($request);

            return $this->performSearchWithAccessControl($request, [
                [
                    IndexedFileText::search($query)->query(function (Eloquent\Builder $builder) use ($includeKinds) {
                        $builder->whereHas('file', function (Eloquent\Builder $builder) use ($includeKinds) {
                            $builder->whereIn('kind', $includeKinds);
                        })->with('file', 'file.directory');
                    }),
                    function (IndexedFileText $result) {
                        return $result->file->directory->path.'/'.$result->file->name;
                    },
                    function (IndexedFileText $result) use ($pathPrefix) {
                        // TODO: As above, if we do this a lot, add a materialised path to IndexedFile
                        return $pathPrefix === null || str_starts_with($result->__path__, $pathPrefix);
                    },
                ],
            ], 20);
        });

        $highlighter = new \TeamTNT\TNTSearch\Support\Highlighter;
        $stemmedQuery = (new \TeamTNT\TNTSearch\Stemmer\PorterStemmer)->stem($query);

        return $results->map(function (IndexedFileText $result) use ($highlighter, $stemmedQuery) {
            $shortText = $highlighter->extractRelevant($stemmedQuery, $result->text, 150, 25, "\u{2026}");
            $shortText = str_replace("\n", ' ', $shortText);

            return [
                'kind' => $result->file->kind->value,
                'name' => $result->file->name,
                'path' => $result->__path__,
                'thumbnail' => $result->file->thumbnail,
                'text' => $shortText,
                'score' => $result->__tntSearchScore__,
            ];
        });
    }

    /**
     * @return array{ includeKinds: list<string>, pathPrefix: string|null }
     */
    private function getFiltersFromRequest(Request $request): array
    {
        $validKinds = array_map(fn ($kind) => $kind->value, MediaContentKind::cases());
        $includeKinds = array_values(array_intersect($request->query->all('kind'), $validKinds));
        if (count($includeKinds) === 0) {
            $includeKinds = $validKinds;
        }

        $pathPrefix = Path::resolve($request->query('path'));

        return [$includeKinds, $pathPrefix];
    }

    /**
     * @param  list<array{Scout\Builder, callable(Eloquent\Model $model): string, callable(Eloquent\Model $model): bool}>  $queries
     */
    private function performSearchWithAccessControl(Request $request, array $queries, int $limit): Collection
    {
        $user = $request->user();
        $accessControlOverrideUser = User::getAccessControlOverrideUser($request);
        $accessControlUser = $accessControlOverrideUser ?? $user;

        $accessControlEntries = AccessControlEntry::affectingPath('')->withCount([
            'users' => function (Eloquent\Builder $query) use ($accessControlUser) {
                $query->whereKey($accessControlUser->getKey());
            },
        ])->get()->reverse();

        $perQueryResultGenerators = array_map(function ($query) use ($limit, $accessControlUser) {
            [$builder, $mapModelToPath] = $query;

            // This is effectively the TNTSearchEngine::paginate implementation, but with only one index search performed,
            // and without the inefficient self-join query it does to discard results when query constraints are applied.
            // We check the type as our hackery probably doesn't work with any other engines.

            $engine = $builder->model->searchableUsing();
            if (!($engine instanceof \TeamTNT\Scout\Engines\TNTSearchEngine)) {
                throw new \LogicException('Bad search engine for '.get_class($builder->model));
            }

            $maxPages = 5;

            $indexResults = $builder->take(($limit * 2 * $maxPages) + 1)->raw();
            $totalIndexResults = $indexResults['hits'];
            $indexPages = array_chunk($indexResults['ids'], $limit * 2);

            $totalResults = 0;

            foreach ($indexPages as $page => $indexPage) {
                if ($page >= $maxPages) {
                    // If we try and post-filter too many pages of results, just give up early.
                    event(new SearchOverheated(get_class($builder->model), $builder->query, $accessControlUser, $totalResults, $totalIndexResults));

                    return;
                }

                Log::debug(sprintf('Starting new search result page %d/%d', $page + 1, $maxPages));

                $indexResults['ids'] = $indexPage;
                $items = $engine->map($builder, $indexResults, $builder->model);

                foreach ($items as $result) {
                    $path = $mapModelToPath($result);
                    $result->setAttribute('__path__', $path);

                    $totalResults++;

                    yield $result;
                }
            }
        }, $queries);

        $results = new Collection;
        $nextQueryResult = array_fill(0, count($perQueryResultGenerators), null);

        while (true) {
            $nextResult = null;
            $bestResultIndex = null;

            // Make sure we have a result from each of the queries and get the highest score result
            foreach ($perQueryResultGenerators as $i => $generator) {
                if ($nextQueryResult[$i] === null && $generator->valid()) {
                    $nextQueryResult[$i] = $generator->current();
                    $generator->next();
                }

                if ($nextQueryResult[$i] === null) {
                    continue;
                }

                if ($nextResult == null || $nextQueryResult[$i]->__tntSearchScore__ > $nextResult->__tntSearchScore__) {
                    $bestResultIndex = $i;
                    $nextResult = $nextQueryResult[$bestResultIndex];
                }
            }

            // We've run out of results in all queries
            if ($bestResultIndex === null) {
                break;
            }

            // Mark it as used and in need of fetching the next candidate
            $nextQueryResult[$bestResultIndex] = null;

            $path = $nextResult->__path__;

            // First check user access
            $hasAccess = false;
            foreach ($accessControlEntries as $entry) {
                if (str_starts_with($path, $entry->path)) {
                    $hasAccess = $entry->users_count > 0;
                    break;
                }
            }

            if (!$hasAccess) {
                continue;
            }

            // Then any custom filter function provided
            $filterModel = $queries[$bestResultIndex][2] ?? null;
            if ($filterModel !== null && !$filterModel($nextResult)) {
                continue;
            }

            // Merge the result into the collection, summing up the scores
            if ($results->has($path)) {
                $newScore = $results[$path]->__tntSearchScore__ + $nextResult->__tntSearchScore__;
                $results[$path]->setAttribute('__tntSearchScore__', $newScore);
            } else {
                $results->put($path, $nextResult);
            }

            if ($results->count() >= $limit) {
                break;
            }
        }

        return $this->sortResults($results);
    }

    private function sortResults(Collection $results): Collection
    {
        return $results->sort(function ($a, $b) {
            $scoreA = $a->__tntSearchScore__;
            $scoreB = $b->__tntSearchScore__;

            // Compare scores with epsilon
            $epsilon = 1e-6;
            if (abs($scoreA - $scoreB) > $epsilon) {
                // Higher score first
                return ($scoreA > $scoreB) ? -1 : 1;
            }

            // Then by path depth (more separators = deeper path = lower priority)
            $depthA = substr_count($a->__path__, '/');
            $depthB = substr_count($b->__path__, '/');
            if ($depthA !== $depthB) {
                // Shallow path first
                return ($depthA < $depthB) ? -1 : 1;
            }

            // Finally natural sort on path (case-insensitive)
            return strnatcasecmp($a->__path__, $b->__path__);
        })->values();
    }
}

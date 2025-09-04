<?php

namespace App\Http\Controllers;

use App\Events\SearchOverheated;
use App\Media\MediaContentKind;
use App\Models\AccessControlEntry;
use App\Models\IndexedDirectory;
use App\Models\IndexedFile;
use App\Models\IndexedFileText;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Scout\Builder;
use TeamTNT\TNTSearch\Support\NGramTokenizer;

class SearchController extends Controller
{
    public function name(Request $request, string $query = '')
    {
        $tokenizer = new NGramTokenizer(3, 3);
        $queryTrigrams = implode(' ', $tokenizer->tokenize($query));

        $results = app('clockwork')->event('Filename Search')->run(function () use ($request, $queryTrigrams) {
            return $this->performSearchWithAccessControl($request, [
                [
                    IndexedDirectory::search($queryTrigrams),
                    function ($result) {
                        // Give directories a score boost, as they're much rarer.
                        $result->setAttribute('__tntSearchScore__', $result->__tntSearchScore__ * 1.5);

                        return $result->path;
                    },
                ],
                [
                    IndexedFile::search($queryTrigrams)->query(function ($builder) {
                        $builder->with('directory');
                    }),
                    function ($result) {
                        return $result->directory->path.'/'.$result->name;
                    },
                ],
            ], 20);
        });

        // TODO: If several results have nearly-identical scores, sort by number of path separators, then by name.

        return $results->map(function ($result) {
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
            $builder = IndexedFileText::search($query)->query(function ($builder) {
                $builder->with('file', 'file.directory');
            });

            return $this->performSearchWithAccessControl($request, [
                [
                    $builder,
                    function ($result) {
                        return $result->file->directory->path.'/'.$result->file->name;
                    },
                ],
            ], 20);
        });

        $highlighter = new \TeamTNT\TNTSearch\Support\Highlighter;
        $stemmedQuery = (new \TeamTNT\TNTSearch\Stemmer\PorterStemmer)->stem($query);

        return $results->map(function ($result) use ($highlighter, $stemmedQuery) {
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
     * @param  list<array{Builder, callable(\Illuminate\Database\Eloquent\Model $model): string}>  $queries
     */
    private function performSearchWithAccessControl(Request $request, array $queries, int $limit): Collection
    {
        $user = $request->user();
        $accessControlOverrideUser = User::getAccessControlOverrideUser($request);
        $accessControlUser = $accessControlOverrideUser ?? $user;

        $accessControlEntries = AccessControlEntry::affectingPath('')->withCount([
            'users' => function (\Illuminate\Database\Eloquent\Builder $query) use ($accessControlUser) {
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

        return $results->sortByDesc('__tntSearchScore__')->values();
    }
}

<?php

namespace App\Http\Controllers;

use App\Events\SearchOverheated;
use App\Media\MediaContentKind;
use App\Models\AccessControlEntry;
use App\Models\IndexedDirectory;
use App\Models\IndexedFile;
use App\Models\IndexedFileText;
use App\Models\User;
use App\Search\DocumentFilter;
use App\Search\FilterableSqliteEngine;
use App\Search\SearchScope;
use App\Utilities\Path;
use Illuminate\Database\Eloquent;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Laravel\Scout;
use TeamTNT\Scout\Engines\TNTSearchEngine;
use TeamTNT\TNTSearch\Stemmer\PorterStemmer;
use TeamTNT\TNTSearch\Support\Highlighter;
use TeamTNT\TNTSearch\Tokenizer\NGramTokenizer;

class SearchController extends Controller
{
    /** @var \WeakMap<SearchScope, array<int, string>> */
    private \WeakMap $allowedDirectories;

    public function __construct()
    {
        $this->allowedDirectories = new \WeakMap;
    }

    public function index()
    {
        return view('search.index');
    }

    public function name(Request $request, string $query = '')
    {
        $tokenizer = new NGramTokenizer(3, 3);
        $queryTrigrams = implode(' ', $tokenizer->tokenize($query));

        [$includeKinds, $pathPrefix] = $this->getFiltersFromRequest($request);

        $queries = [];

        // If the directory type is in the requested kinds, add the directory index.
        if (in_array(MediaContentKind::Directory->value, $includeKinds, true)) {
            $queries[] = [
                'builder' => IndexedDirectory::search($queryTrigrams),
                'path' => function (IndexedDirectory $result) {
                    // Give directories a score boost, as they're much rarer.
                    $result->setAttribute('__tntSearchScore__', $result->__tntSearchScore__ * 1.5);

                    return $result->path;
                },
                'filter' => function (SearchScope $scope) use ($pathPrefix) {
                    if ($scope->isUnrestricted()) {
                        return null;
                    }

                    // Directories are their own documents. Searching within a directory shouldn't find the directory.
                    $directoryIds = array_keys(array_filter($this->getAllowedDirectories($scope), fn ($path) => strval($path) !== $pathPrefix));

                    return new DocumentFilter(documentIds: $directoryIds);
                },
            ];
        }

        // If anything other than the directory type is requested, add the file index.
        if (count($includeKinds) !== 1 || $includeKinds[0] !== MediaContentKind::Directory->value) {
            $queries[] = [
                'builder' => IndexedFile::search($queryTrigrams)->query(function (Eloquent\Builder $builder) use ($includeKinds) {
                    $builder->whereIn('kind', $includeKinds)->with('directory');
                }),
                'path' => function (IndexedFile $result) {
                    return $result->directory->path.'/'.$result->name;
                },
                'filter' => function (SearchScope $scope) use ($includeKinds) {
                    return $this->makeFileFilter($scope, $includeKinds);
                },
            ];
        }

        $searchOverheated = false;
        Event::listen(SearchOverheated::class, function () use (&$searchOverheated) {
            $searchOverheated = true;
        });

        $results = app('clockwork')->event('Filename Search')->run(function () use ($request, $queries, $pathPrefix) {
            return $this->performSearchWithAccessControl($request, $queries, $pathPrefix, 20);
        });

        return [
            'overheated' => $searchOverheated,
            'results' => $results->map(function (IndexedDirectory|IndexedFile $result) {
                return match (true) {
                    $result instanceof IndexedDirectory => [
                        'kind' => MediaContentKind::Directory->value,
                        'name' => $result->name,
                        'path' => $result->path,
                        'link' => '/'.$result->__path__.'/',
                        'score' => $result->__tntSearchScore__,
                    ],
                    $result instanceof IndexedFile => [
                        'kind' => $result->kind->value,
                        'name' => $result->name,
                        'path' => $result->directory->path,
                        'thumbnail' => $result->thumbnail,
                        'link' => media_info_url($result->__path__),
                        'score' => $result->__tntSearchScore__,
                    ],
                };
            }),
        ];
    }

    public function content(Request $request, string $query = '')
    {
        $searchOverheated = false;
        Event::listen(SearchOverheated::class, function () use (&$searchOverheated) {
            $searchOverheated = true;
        });

        $results = app('clockwork')->event('Content Search')->run(function () use ($request, $query) {
            [$includeKinds, $pathPrefix] = $this->getFiltersFromRequest($request);

            return $this->performSearchWithAccessControl($request, [
                [
                    'builder' => IndexedFileText::search($query)->query(function (Eloquent\Builder $builder) use ($includeKinds) {
                        $builder->whereHas('file', function (Eloquent\Builder $builder) use ($includeKinds) {
                            $builder->whereIn('kind', $includeKinds);
                        })->with('file', 'file.directory');
                    }),
                    'path' => function (IndexedFileText $result) {
                        return $result->file->directory->path.'/'.$result->file->name;
                    },
                    'filter' => function (SearchScope $scope) use ($includeKinds) {
                        return $this->makeFileFilter($scope, $includeKinds);
                    },
                ],
            ], $pathPrefix, 20);
        });

        $highlighter = new Highlighter;
        $stemmedQuery = (new PorterStemmer)->stem($query);

        return [
            'overheated' => $searchOverheated,
            'results' => $results->map(function (IndexedFileText $result) use ($highlighter, $stemmedQuery) {
                // TODO: Add markup to highlight the term matched.
                $shortText = $highlighter->extractRelevant($stemmedQuery, $result->text, 150, 25, "\u{2026}");
                $shortText = str_replace("\n", ' ', $shortText);

                return [
                    'kind' => $result->file->kind->value,
                    'name' => $result->file->name,
                    'path' => $result->file->directory->path,
                    'thumbnail' => $result->file->thumbnail,
                    'text' => $shortText,
                    'link' => media_info_url($result->__path__),
                    'score' => $result->__tntSearchScore__,
                ];
            }),
        ];
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
        if ($pathPrefix === '') {
            // The root, so no restriction.
            $pathPrefix = null;
        }

        return [$includeKinds, $pathPrefix];
    }

    /**
     * IDs and paths of the directories in scope, shared by the searches in a request.
     *
     * @return array<int, string>
     */
    private function getAllowedDirectories(SearchScope $scope): array
    {
        if (!isset($this->allowedDirectories[$scope])) {
            $this->allowedDirectories[$scope] = app('clockwork')->event('Search Scope (directories)')->run(function () use ($scope) {
                $query = IndexedDirectory::query();
                $scope->applyToDirectories($query, 'indexed_directories.path');

                // toBase(), as Eloquent's pluck() hydrates a model per row for cast columns, including the key.
                return $query->toBase()->pluck('indexed_directories.path', 'indexed_directories.id')->all();
            });
        }

        return $this->allowedDirectories[$scope];
    }

    /**
     * Build the index filter for a search over documents stored with their file's directory and kind.
     *
     * Files are in scope if their directory is, as access control rules are only expected to target directories.
     *
     * @param  list<string>  $includeKinds
     */
    private function makeFileFilter(SearchScope $scope, array $includeKinds): ?DocumentFilter
    {
        $kinds = (count($includeKinds) === count(MediaContentKind::cases())) ? null : $includeKinds;

        if ($scope->isUnrestricted()) {
            return ($kinds === null) ? null : new DocumentFilter(kinds: $kinds);
        }

        return new DocumentFilter(
            directoryIds: array_keys($this->getAllowedDirectories($scope)),
            kinds: $kinds,
        );
    }

    /**
     * Each query provides:
     * - builder: the Scout search.
     * - path: maps a result model to its media path.
     * - filter: given the search scope (access control + path filter), returns the filter to apply inside the index
     *           (see FilterableSqliteEngine), or null for none. This keeps results the user can't see, or that are
     *           outside the path filter, from crowding out the ones they can and overheating the search.
     *
     * @param  list<array{builder: Scout\Builder, path: callable(Eloquent\Model $model): string, filter: callable(SearchScope $scope): ?DocumentFilter}>  $queries
     */
    private function performSearchWithAccessControl(Request $request, array $queries, ?string $pathPrefix, int $limit): Collection
    {
        $user = $request->user();
        $accessControlOverrideUser = User::getAccessControlOverrideUser($request);
        $accessControlUser = $accessControlOverrideUser ?? $user;

        $accessControlEntries = AccessControlEntry::affectingPath('')->withCount([
            'users' => function (Eloquent\Builder $query) use ($accessControlUser) {
                $query->whereKey($accessControlUser->getKey());
            },
        ])->get()->reverse();

        $scope = SearchScope::fromAccessControlEntries($accessControlEntries, $pathPrefix);
        if ($scope->isEmpty()) {
            return new Collection;
        }

        $perQueryResultGenerators = array_map(function ($query) use ($limit, $accessControlUser, $scope) {
            $builder = $query['builder'];
            $mapModelToPath = $query['path'];

            // This is effectively the TNTSearchEngine::paginate implementation, but with only one index search performed,
            // and without the inefficient self-join query it does to discard results when query constraints are applied.
            // We check the type as our hackery probably doesn't work with any other engines.

            $engine = $builder->model->searchableUsing();
            if (!($engine instanceof TNTSearchEngine)) {
                throw new \LogicException('Bad search engine for '.get_class($builder->model));
            }

            // TODO: We may want to tune this depending on the type of search being performed.
            $maxPages = 10;

            // We had an issue here where some queries were getting nonsense scores back from the index.
            // The cause of this was hitting the "maxDocs" limit, which is counter per-keyword.
            // Looks like any keywork with more than maxDocs hits is likely to introduce scoring issues due to being incomplete.
            // Doesn't look like they're ever queried as a group of keywords, that's handled later by summing the scores.
            // TODO: Part of the issue here appears to be a lack of stopword handling.

            $filter = app('clockwork')->event('Search Filter ('.class_basename($builder->model).')')->run(function () use ($query, $scope) {
                return $query['filter']($scope);
            });

            if ($filter?->isEmpty()) {
                return;
            }

            $tntEngine = $engine->getTNT()->engine;
            if ($filter !== null && !($tntEngine instanceof FilterableSqliteEngine)) {
                throw new \LogicException('Search index engine for '.get_class($builder->model).' does not support filtering');
            }

            try {
                // The engine instance is shared, so the filter must only be in place for this one index search.
                if ($tntEngine instanceof FilterableSqliteEngine) {
                    $tntEngine->setDocumentFilter($filter);
                }

                $indexResults = app('clockwork')->event('Search Index ('.class_basename($builder->model).')')->run(function () use ($builder, $limit, $maxPages) {
                    return $builder->take(($limit * 2 * $maxPages) + 1)->raw();
                });
            } finally {
                if ($tntEngine instanceof FilterableSqliteEngine) {
                    $tntEngine->setDocumentFilter(null);
                }
            }

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

            // First check user access.
            // The scope filter already excludes anything the user can't access, but this remains the authoritative check.
            $hasAccess = false;
            foreach ($accessControlEntries as $entry) {
                // The deepest rule that is the path itself or one of its ancestors wins.
                // Entries are in reverse path order, so the first match is the deepest.
                $entryPath = strval($entry->path);
                if ($entryPath === '' || $path === $entryPath || str_starts_with($path, $entryPath.'/')) {
                    $hasAccess = $entry->users_count > 0;
                    break;
                }
            }

            if (!$hasAccess) {
                continue;
            }

            // Merge the result into the collection, taking the highest score
            if ($results->has($path)) {
                $newScore = max($results[$path]->__tntSearchScore__, $nextResult->__tntSearchScore__);
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

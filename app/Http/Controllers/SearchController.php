<?php

namespace App\Http\Controllers;

use App\Events\SearchOverheated;
use App\Media\MediaContentKind;
use App\Models\IndexedDirectory;
use App\Models\IndexedFile;
use App\Models\IndexedFileText;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Laravel\Scout\Builder;
use TeamTNT\TNTSearch\Support\NGramTokenizer;

class SearchController extends Controller
{
    public function name(Request $request, string $query = '')
    {
        $tokenizer = new NGramTokenizer(3, 3);
        $queryTrigrams = implode(' ', $tokenizer->tokenize($query));

        $directoryResults = app('clockwork')->event('Directory Search')->run(function () use ($request, $queryTrigrams) {
            $builder = IndexedDirectory::search($queryTrigrams);

            return $this->performSearchWithAccessControl($request, $builder, function ($result) {
                return $result->path;
            }, 20);
        });

        $fileResults = app('clockwork')->event('File Search')->run(function () use ($request, $queryTrigrams) {
            $builder = IndexedFile::search($queryTrigrams)->query(function ($builder) {
                $builder->with('directory');
            });

            return $this->performSearchWithAccessControl($request, $builder, function ($result) {
                return $result->directory->path.'/'.$result->name;
            }, 20);
        });

        // TODO: It'd be more efficient if performSearchWithAccessControl could handle this for us,
        //       rather than trying to get N items with access from each.
        // TODO: If several results have nearly-identical scores, sort by number of path separators, then by name.
        $results = $directoryResults->each(function ($result) {
            // Give directories a score boost, as they're much rarer.
            $result->setAttribute('__tntSearchScore__', $result->__tntSearchScore__ * 1.5);
        })->merge($fileResults)->sortByDesc('__tntSearchScore__')->take(20)->values();

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

            return $this->performSearchWithAccessControl($request, $builder, function ($result) {
                return $result->file->directory->path.'/'.$result->file->name;
            }, 20);
        });

        $highlighter = new \TeamTNT\TNTSearch\Support\Highlighter;

        return $results->map(function ($result) use ($query, $highlighter) {
            $shortText = $highlighter->extractRelevant($query, $result->text, 150, 25, "\u{2026}");
            $shortText = str_replace("\n", ' ', $shortText);

            return [
                'kind' => $result->file->kind->value,
                'name' => $result->file->name,
                'path' => $result->__path__,
                'text' => $shortText,
                'score' => $result->__tntSearchScore__,
            ];
        });
    }

    /**
     * @param  callable(\Illuminate\Database\Eloquent\Model $model): string  $mapModelToPath
     */
    private function performSearchWithAccessControl(Request $request, Builder $builder, callable $mapModelToPath, int $limit): Collection
    {
        $user = $request->user();
        $accessControlOverrideUser = User::getAccessControlOverrideUser($request);
        $accessControlUser = $accessControlOverrideUser ?? $user;

        // This is effectively the TNTSearchEngine::paginate implementation, but with only one index search performed,
        // and without the inefficient self-join query it does to discard results when query constraints are applied.
        // We check the type as our hackery probably doesn't work with any other engines.

        $engine = $builder->model->searchableUsing();
        if (!($engine instanceof \TeamTNT\Scout\Engines\TNTSearchEngine)) {
            throw new \LogicException('Bad search engine for '.get_class($builder->model));
        }

        $indexResults = $builder->raw();
        $indexPages = array_chunk($indexResults['ids'], $limit * 2);

        $results = new Collection;
        foreach ($indexPages as $page => $indexPage) {
            if ($page > 3) {
                // If we try and post-filter too many pages of results, just give up early.
                event(new SearchOverheated(get_class($builder->model), $builder->query, $accessControlUser, $results->count(), $indexResults['hits']));
                break;
            }

            $indexResults['ids'] = $indexPage;
            $items = $engine->map($builder, $indexResults, $builder->model);

            foreach ($items as $result) {
                $path = $mapModelToPath($result);
                $result->setAttribute('__path__', $path);

                // TODO: Implement a new version of this that caches access control entries it already knows about
                //       For each one, we know if there are any rules under it already, so don't need to re-query.
                $accessControlInfo = $accessControlUser->getPathAccessInfo($path);
                if (!$accessControlInfo['']) {
                    continue;
                }

                $results->push($result);
                if ($results->count() >= $limit) {
                    break 2;
                }
            }
        }

        return $results;
    }
}

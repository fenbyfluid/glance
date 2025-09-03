<?php

namespace App\Http\Controllers;

use App\Exceptions\ImageExtractionIncompleteException;
use App\Jobs\IndexDirectoryJob;
use App\Media\MediaContentKind;
use App\Media\Sources\DirectorySource;
use App\Media\TranscodeManager;
use App\Models\IndexedDirectory;
use App\Models\User;
use App\Utilities\Path;
use Illuminate\Bus\BatchRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MediaController extends Controller
{
    public function index(Request $request, string $unsafePath = ''): View|Response
    {
        $path = Path::resolve($unsafePath);

        $user = $request->user();
        $accessControlOverrideUser = User::getAccessControlOverrideUser($request);
        $accessControlInfo = ($accessControlOverrideUser ?? $user)->getPathAccessInfo($path);

        // User has no access to anything under here, nothing to do.
        if (count(array_filter($accessControlInfo)) === 0) {
            abort(404);
        }

        $filesystemPath = config('media.path').'/'.$path;

        $pathInfo = new \SplFileInfo($filesystemPath);

        if ($pathInfo->isFile()) {
            // For a file, only direct access is suitable.
            if (!$accessControlInfo['']) {
                abort(404);
            }

            $this->ensurePathCanonical($request, false);

            if ($request->query->has('_debug_ocr') && $user->can('admin')) {
                return $this->debugImageTextExtraction($filesystemPath);
            }

            config(['clockwork.enable' => false]);

            return response()->file($filesystemPath)->setPrivate();
        }

        if (!$pathInfo->isDir()) {
            abort(404);
        }

        $this->ensurePathCanonical($request, true);

        /** @var IndexedDirectory $directoryEntry */
        $directoryEntry = IndexedDirectory::where('path', $path)->first();

        if (!$directoryEntry || $directoryEntry->isIndexOutdated($pathInfo)) {
            $lockKey = __CLASS__.':index:'.hash('xxh128', $path);
            $lock = Cache::lock($lockKey, 60);

            $batchKey = $lockKey.':batch';

            if ($lock->get()) {
                $lockOwner = $lock->owner();
                $batch = (new IndexDirectoryJob($path, 0, rootDirectory: $directoryEntry))
                    ->asBatch()
                    ->finally(function () use ($lockKey, $lockOwner) {
                        Cache::restoreLock($lockKey, $lockOwner)->release();
                    })->dispatch();

                Cache::put($batchKey, $batch->id, 60);
            } else {
                $batch = resolve(BatchRepository::class)->find(Cache::get($batchKey));
            }

            // TODO: Show a proper job status screen with progress info.
            // TODO: Sometimes when the page refershes to the directory contents, it hasn't actually updated yet.
            return response($batch ?? '')->header('Refresh', '1');
        }

        // TODO: There are a few ideas here for implementing all the sorting and pagination in SQL land, and it's
        //       very nearly good enough, but it's probably not worth the effort to get perfect.
        //       Using two selects, with a +1 limit, and subtracting the count of the dirs from the limit for files
        //       gets us the right amount with a canary for the next page. Using the FIELD() function with orderByRaw
        //       gets us files sorted correctly by type ready for grouping. However, natural sorting is quite important
        //       and doesn't appear to have any reasonable analogue. Our largest dir has about 2,500 children.
        //       Our goal with pagination is almost exclusively to reduce the HTML size, so server-side filtering is fine.

        // TODO: What is this going to look like? Do we need some sort of parallel for file access?
        $source = $this->getSourceForPath($directoryEntry, $accessControlInfo);

        // TODO: If the directory includes an index.html file, it should be displayed instead of the directory listing.

        $contents = app('clockwork')->event('Getting contents')->run(function () use ($source) {
            // TODO: Paginate this.
            return $source->getContents();
        });

        // TODO: This needs to be an option per-directory.
        $grouped = app('clockwork')->event('Grouping contents')->run(function () use ($contents) {
            $grouped = array_fill_keys(array_map(fn ($case) => $case->value, MediaContentKind::cases()), []);

            foreach ($contents as $content) {
                $grouped[$content->kind->value][] = $content;
            }

            foreach ($grouped as $i => &$group) {
                if (empty($group)) {
                    unset($grouped[$i]);

                    continue;
                }

                // TODO: Keeping the arrays sorted would be more efficient.
                usort($group, fn ($a, $b) => strnatcasecmp($a->label, $b->label));
            }
            unset($group);

            return $grouped;
        });

        return view('media.index', [
            'path' => $path,
            'breadcrumbs' => $this->getCrumbsForPath($path),
            'readme' => $source->getReadmeHtml(),
            'contents' => $grouped,
            'accessControlOverrideUser' => $accessControlOverrideUser,
        ]);
    }

    public function stream(Request $request, string $unsafePath = ''): Response
    {
        $path = Path::resolve($unsafePath);

        if (preg_match('/^(.+)\.m3u8(?:\/(\d+)\.ts)?$/', $path, $matches, PREG_UNMATCHED_AS_NULL) !== 1) {
            abort(404);
        }

        $this->ensurePathCanonical($request, false);

        $path = $matches[1];
        $segment = ($matches[2] !== null) ? (int) $matches[2] : null;

        $user = $request->user();
        $accessControlOverrideUser = User::getAccessControlOverrideUser($request);
        $accessControlInfo = ($accessControlOverrideUser ?? $user)->getPathAccessInfo($path);
        if (!$accessControlInfo['']) {
            abort(404);
        }

        $filesystemPath = config('media.path').'/'.$path;

        if (!is_file($filesystemPath)) {
            abort(404);
        }

        $transcodeSessionId = hash('xxh128', $request->session()->getId().$filesystemPath);
        $transcodeManager = new TranscodeManager($transcodeSessionId, $filesystemPath);

        if ($segment === null) {
            return $transcodeManager->getPlaylistResponse($request->url());
        } else {
            return $transcodeManager->getSegmentResponse($segment);
        }
    }

    public function image(string $id): Response
    {
        if (!Str::isUuid($id)) {
            abort(404);
        }

        $filesystemPath = Path::makeThumbnailPath($id);
        if (!file_exists($filesystemPath)) {
            abort(404);
        }

        config(['clockwork.enable' => false]);

        return response()->file($filesystemPath);
    }

    public function handleHttpException(Request $request, HttpException $exception): ?Response
    {
        $route = $request->route();
        if ($route?->getActionName() !== __CLASS__.'@index') {
            return null;
        }

        $path = Path::resolve($route->parameter('path', ''));

        $statusCode = $exception->getStatusCode();
        $message = $exception->getMessage() ?:
            Response::$statusTexts[$statusCode] ??
            sprintf('Error %03d', $statusCode);

        // TODO: Create a specific error view
        return response(view('media.index', [
            'path' => $path,
            'breadcrumbs' => $this->getCrumbsForPath($path),
            'readme' => $message,
            'contents' => [],
            'accessControlOverrideUser' => User::getAccessControlOverrideUser($request),
        ]), $statusCode);
    }

    private function ensurePathCanonical(Request $request, bool $needsTrailingSlash): void
    {
        $requestPathInfo = $request->getPathInfo();
        $hasTrailingSlash = str_ends_with($requestPathInfo, '/') ||
            str_ends_with($requestPathInfo, '%2F') ||
            str_ends_with($requestPathInfo, '%2f');

        if ($hasTrailingSlash === $needsTrailingSlash) {
            return;
        }

        $targetUrl = preg_replace('#/?(\?|$)#', $needsTrailingSlash ? '/$1' : '$1', $request->getUri(), 1);

        abort(response()->redirectTo($targetUrl));
    }

    private function getCrumbsForPath(string $path): array
    {
        $crumbs = [];

        $running = null;
        $crumbs[] = (object) [
            'label' => '',
            'path' => media_url('/'),
        ];

        foreach (explode('/', $path) as $crumb) {
            if ($running === null) {
                $running = $crumb;
            } else {
                $running .= '/'.$crumb;
            }

            $crumbs[] = (object) [
                'label' => $crumb,
                'path' => media_url($running.'/'),
            ];
        }

        return $crumbs;
    }

    private function getSourceForPath(IndexedDirectory $indexedDirectory, array $accessControlInfo): DirectorySource
    {
        // TODO
        return new DirectorySource($indexedDirectory, $accessControlInfo);
    }

    private function debugImageTextExtraction(string $path)
    {
        ini_set('max_execution_time', 0);

        $spans = [];
        $imageExtractionState = null;

        do {
            try {
                $eventName = sprintf('Extract Text Boxes (%d)', $imageExtractionState['offset'] ?? 0);
                $spans = app('clockwork')->event($eventName)->run(function () use ($path, $imageExtractionState) {
                    return \App\Jobs\ExtractTextJob::extractTextBoxes($path, $imageExtractionState);
                });

                $imageExtractionState = null;
            } catch (ImageExtractionIncompleteException $exception) {
                $imageExtractionState = $exception->getStateForNextJob();
            }
        } while ($imageExtractionState !== null);

        $altText = app('clockwork')->event('Extract Text From Boxes')->run(function () use ($spans) {
            $text = \App\Jobs\ExtractTextJob::extractTextFromBoxes($spans);

            return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5);
        });

        $imageUrl = app('clockwork')->event('Visualize Text Boxes')->run(function () use ($path, $spans) {
            $image = \App\Jobs\ExtractTextJob::visualizeTextBoxes($path, $spans);
            $imageUrl = 'data:image/png;base64,'.base64_encode($image->pngsave_buffer());

            return htmlspecialchars($imageUrl, ENT_QUOTES | ENT_HTML5);
        });

        return response(<<<EOT
            <!DOCTYPE html>
            <html lang="en">
            <body>
            <img src="$imageUrl" alt="$altText" title="$altText" style="max-width:100%; height: auto;">
        EOT);
    }
}

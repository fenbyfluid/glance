<?php

namespace App\Http\Controllers;

use App\Auth\PathAccess;
use App\Exceptions\ImageExtractionIncompleteException;
use App\Jobs\ExtractTextJob;
use App\Jobs\IndexDirectoryJob;
use App\Media\FileDetails;
use App\Media\GroupedMediaPaginator;
use App\Media\MediaContentKind;
use App\Media\Sources\DirectorySource;
use App\Media\TranscodeManager;
use App\Models\IndexedDirectory;
use App\Models\IndexedFile;
use App\Models\User;
use App\Utilities\Path;
use Illuminate\Bus\BatchRepository;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
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
        $pathAccess = PathAccess::forRequest($request);

        // Nothing under here is visible, nothing to do. (Checked before looking at the filesystem, so it can't tell
        // what exists.)
        $accessControlInfo = $pathAccess->ensureVisible($path, isDirectory: true);

        $filesystemPath = config('media.path').'/'.$path;

        $pathInfo = new \SplFileInfo($filesystemPath);

        if ($pathInfo->isFile()) {
            // For a file, only direct access is suitable.
            if (!$accessControlInfo['']) {
                $pathAccess->deny();
            }

            $this->ensurePathCanonical($request, false);

            if ($request->query->has('_debug_ocr') && $user->can('admin')) {
                return $this->debugImageTextExtraction($request, $filesystemPath);
            }

            config(['clockwork.enable' => false]);

            return response()->file($filesystemPath)->setPrivate();
        }

        if (!$pathInfo->isDir()) {
            abort(404);
        }

        $this->ensurePathCanonical($request, true);

        $directoryEntry = IndexedDirectory::where('path', $path)->first();

        $indexStatusResponse = $this->ensureDirectoryIndexed($request, $path, $pathInfo, $directoryEntry);
        if ($indexStatusResponse) {
            return $indexStatusResponse;
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
            return $source->getContents();
        });

        foreach ($contents as $content) {
            $content->isOnlyThroughLinks = $pathAccess->isOnlyThroughLinks($path, $content->label);
        }

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

        $itemsPerPage = 100;
        $currentPage = $request->query->get('page', 1);

        $skippedItems = 0;
        $currentPageCount = 0;
        $currentPageContents = [];

        foreach ($grouped as $i => $group) {
            foreach ($group as $item) {
                if ($skippedItems < (($currentPage - 1) * $itemsPerPage)) {
                    $skippedItems++;

                    continue;
                }

                if (!isset($currentPageContents[$i])) {
                    $currentPageContents[$i] = [];
                }

                $currentPageContents[$i][] = $item;

                $currentPageCount++;
                if ($currentPageCount >= $itemsPerPage) {
                    break 2;
                }
            }
        }

        $paginator = new GroupedMediaPaginator($currentPageContents, count($contents), $itemsPerPage, $currentPage, [
            'path' => $request->getPathInfo(),
            'itemCount' => $currentPageCount,
        ]);

        return view('media.index', [
            'path' => $path,
            'breadcrumbs' => $this->getCrumbsForPath($path),
            'readme' => $source->getReadmeHtml(),
            'contents' => $paginator,
            'accessControlOverrideUser' => $accessControlOverrideUser,
            'canShareDirectory' => $this->canShare($request, $path),
        ]);
    }

    public function info(Request $request, string $unsafePath = ''): View
    {
        $path = Path::resolve($unsafePath);

        $accessControlOverrideUser = User::getAccessControlOverrideUser($request);
        PathAccess::forRequest($request)->ensureVisible($path, isDirectory: false);

        $filesystemPath = config('media.path').'/'.$path;

        // TODO: Directories could have an info page too (size, counts, README), but there's nothing to play.
        $pathInfo = new \SplFileInfo($filesystemPath);
        if (!$pathInfo->isFile()) {
            abort(404);
        }

        $this->ensurePathCanonical($request, false);

        // TODO: Files that aren't in the index yet only get what the filesystem tells us. We could dispatch an
        //       IndexDirectoryJob for the parent like index() does, but the page is still useful without it.
        $fileEntry = IndexedFile::query()
            ->atPath($path)
            ->with([
                'extractedText',
                'fingerprint.stashScene.studio.parentStudio.parentStudio',
                'fingerprint.stashScene.performers',
                'fingerprint.stashScene.tags',
            ])
            ->first();

        // The index can lag behind the filesystem, in which case what we generated is for the old contents.
        if ($fileEntry?->isIndexOutdated($pathInfo)) {
            $fileEntry = null;
        }

        $details = new FileDetails($pathInfo, $fileEntry);

        $scene = $fileEntry?->fingerprint?->stashScene;

        // A matched scene's description is stored as the file's text for searching, so it'd only repeat it.
        $extractedText = ($scene === null) ? $fileEntry?->extractedText->sortBy('id')->pluck('text')->implode("\n\n") : null;

        return view('media.info', [
            'path' => $path,
            'breadcrumbs' => $this->getCrumbsForPath($path),
            'fileUrl' => media_url($path),
            'details' => $details,
            'file' => $fileEntry,
            'scene' => $scene,
            'extractedText' => ($extractedText !== '') ? $extractedText : null,
            'accessControlOverrideUser' => $accessControlOverrideUser,
            'canShare' => $this->canShare($request, $path),
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

        PathAccess::forRequest($request)->ensureVisible($path, isDirectory: false);

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

    public function image(Request $request, string $id): Response
    {
        if (!Str::isUuid($id)) {
            abort(404);
        }

        // TODO: Signed-in users can load any image by its ID, which can't be guessed but isn't checked against their
        //       access either. Guests only get the images of the files they've been shared.
        $pathAccess = PathAccess::forRequest($request);
        if ($pathAccess->isGuest()) {
            // Separately rather than OR-ed, so each uses its column's index.
            $file = IndexedFile::query()->where('thumbnail', $id)->with('directory')->first()
                ?? IndexedFile::query()->where('filmstrip', $id)->with('directory')->first();

            if ($file === null || !$pathAccess->allows($file->getPath())) {
                abort(404);
            }
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
        if (!in_array($route?->getActionName(), [__CLASS__.'@index', __CLASS__.'@info'], true)) {
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
            'contents' => new LengthAwarePaginator([], 0, 1),
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

    /**
     * Whether to offer sharing the file or directory. Links are created with the user's own access, not one an admin is
     * browsing as or a shared link, so this is the user's own; the dialog only offers public links with `can_share`.
     */
    private function canShare(Request $request, string $path): bool
    {
        return !PathAccess::isGuestRequest($request) && $request->user()->getPathAccessInfo($path)[''];
    }

    private function getSourceForPath(IndexedDirectory $indexedDirectory, array $accessControlInfo): DirectorySource
    {
        // TODO
        return new DirectorySource($indexedDirectory, $accessControlInfo);
    }

    private function ensureDirectoryIndexed(Request $request, string $path, \SplFileInfo $pathInfo, ?IndexedDirectory $directoryEntry): ?Response
    {
        // How often to refresh the indexing progress screen.
        $retryIntervalInMinutes = 1;

        // We could wrap this in a lock as well, but indexing is idempotent and two are unlikely to dispatch simultaneously.
        $batchKey = __CLASS__.':index:'.hash('xxh128', $path).':batch';
        $batchId = Cache::get($batchKey);
        $batch = ($batchId !== null) ? resolve(BatchRepository::class)->find($batchId) : null;

        // If there's no existing batch and the directory needs indexing, start a new job.
        if (!$batch && (!$directoryEntry || $directoryEntry->isIndexOutdated($pathInfo))) {
            $batch = (new IndexDirectoryJob($path, 0, rootDirectory: $directoryEntry))
                ->asBatch()
                ->dispatch();
        }

        // If we have a running batch, reset the TTL and return the status page.
        if ($batch && !$batch->finished()) {
            // Make it last twice as long as the refresh interval.
            Cache::put($batchKey, $batch->id, $retryIntervalInMinutes * 2 * 60);

            return response(view('media.index', [
                'path' => $path,
                'breadcrumbs' => $this->getCrumbsForPath($path),
                'readme' => sprintf('%d/%d', $batch->processedJobs(), $batch->totalJobs),
                'contents' => new LengthAwarePaginator([], 0, 1),
                'accessControlOverrideUser' => User::getAccessControlOverrideUser($request),
            ]))->header('Refresh', $retryIntervalInMinutes);
        }

        // If we had a batch, it's now done, remove the record for it against the directory.
        if ($batchId !== null) {
            Cache::forget($batchKey);
        }

        return null;
    }

    private function debugImageTextExtraction(Request $request, string $path): Response
    {
        ini_set('max_execution_time', 0);

        $spans = [];
        $imageExtractionState = null;

        do {
            try {
                $eventName = sprintf('Extract Text Boxes (%d)', $imageExtractionState['offset'] ?? 0);
                $spans = app('clockwork')->event($eventName)->run(function () use ($path, $imageExtractionState) {
                    return ExtractTextJob::extractTextBoxes($path, $imageExtractionState);
                });

                $imageExtractionState = null;
            } catch (ImageExtractionIncompleteException $exception) {
                $imageExtractionState = $exception->getStateForNextJob();
            }
        } while ($imageExtractionState !== null);

        $imageData = app('clockwork')->event('Visualize Text Boxes')->run(function () use ($path, $spans) {
            $image = ExtractTextJob::visualizeTextBoxes($path, $spans);

            return $image->pngsave_buffer();
        });

        if ($request->prefers(['text/html', 'image/png']) === 'image/png') {
            config(['clockwork.enable' => false]);

            return response($imageData, 200, ['Content-Type' => 'image/png', 'Vary' => 'Accept'])->setPrivate();
        }

        $altText = app('clockwork')->event('Extract Text From Boxes')->run(function () use ($spans) {
            $text = ExtractTextJob::extractTextFromBoxes($spans);

            return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5);
        });

        $imageUrl = 'data:image/png;base64,'.base64_encode($imageData);

        return response(<<<EOT
            <!DOCTYPE html>
            <html lang="en">
            <body>
            <img src="$imageUrl" alt="$altText" title="$altText" style="max-width:100%; height: auto;">
        EOT, 200, ['Vary' => 'Accept'])->setPrivate();
    }
}

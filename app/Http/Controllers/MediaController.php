<?php

namespace App\Http\Controllers;

use App\Media\Sources\DirectorySource;
use App\Media\TranscodeManager;
use App\Utilities\Path;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MediaController extends Controller
{
    public function index(Request $request, string $unsafePath = ''): View|Response
    {
        $path = Path::resolve($unsafePath);

        Gate::authorize('view-media', $path);

        $filesystemPath = config('media.path').'/'.$path;

        if (!file_exists($filesystemPath)) {
            abort(404);
        }

        if (is_file($filesystemPath)) {
            $this->ensurePathCanonical($request, false);

            return response()->file($filesystemPath)->setPrivate();
        }

        $this->ensurePathCanonical($request, true);

        $source = $this->getSourceForPath($path);

        return view('media.index', [
            'path' => $path,
            'breadcrumbs' => $this->getCrumbsForPath($path),
            'readme' => $source->getReadmeHtml(),
            // TODO: Pagination
            'contents' => $source->getContents(),
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

        Gate::authorize('view-media', $path);

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

    public function handleHttpException(Request $request, HttpException $exception): ?Response
    {
        $route = $request->route();
        if ($route?->getActionName() !== __CLASS__.'@index') {
            return null;
        }

        $path = Path::resolve($route->parameter('path'));

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

    private function getSourceForPath(string $path): DirectorySource
    {
        // TODO
        return new DirectorySource($path);
    }
}

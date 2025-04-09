<?php

namespace App\Http\Controllers;

use App\Media\Sources\DirectorySource;
use App\Media\TranscodeManager;
use App\Utilities\Path;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class MediaController extends Controller
{
    public function index(Request $request, string $unsafePath = ''): View|Response
    {
        $path = Path::resolve($unsafePath);

        // TODO: Access control checks on $path

        $filesystemPath = config('media.path').'/'.$path;

        if (!file_exists($filesystemPath)) {
            abort(404);
        }

        if (is_file($filesystemPath)) {
            $this->ensurePathCanonical($request, false);

            return response()->file($filesystemPath)->setPrivate();
        }

        $this->ensurePathCanonical($request, true);

        return view('media.index', [
            'path' => $path,
            'breadcrumbs' => $this->getCrumbsForPath($path),
            'contents' => $this->getPathContents($filesystemPath),
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

        // TODO: Access control checks on $path

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

    private function getPathContents(string $filesystemPath): array
    {
        $source = new DirectorySource($filesystemPath);

        // TODO: Pagination.
        return $source->getContents();
    }
}

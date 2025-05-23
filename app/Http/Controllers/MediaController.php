<?php

namespace App\Http\Controllers;

use App\Media\MediaContentKind;
use App\Media\Sources\DirectorySource;
use App\Media\TranscodeManager;
use App\Models\User;
use App\Utilities\Path;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MediaController extends Controller
{
    public function index(Request $request, string $unsafePath = ''): View|Response
    {
        $path = Path::resolve($unsafePath);

        $user = $request->user();
        $accessControlOverrideUser = $this->getAccessControlOverrideUser($request);
        $accessControlInfo = ($accessControlOverrideUser ?? $user)->getPathAccessInfo($path);

        // User has no access to anything under here, nothing to do.
        if (count(array_filter($accessControlInfo)) === 0) {
            abort(404);
        }

        $filesystemPath = config('media.path').'/'.$path;

        if (!file_exists($filesystemPath)) {
            abort(404);
        }

        if (is_file($filesystemPath)) {
            // For a file, only direct access is suitable.
            if (!$accessControlInfo['']) {
                abort(404);
            }

            $this->ensurePathCanonical($request, false);

            config(['clockwork.enable' => false]);

            return response()->file($filesystemPath)->setPrivate();
        }

        $this->ensurePathCanonical($request, true);

        $source = $this->getSourceForPath($path, $accessControlInfo);

        $contents = app('clockwork')->event('Getting contents')->run(function () use ($source) {
            // TODO: How do we handle pagination?
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
        $accessControlOverrideUser = $this->getAccessControlOverrideUser($request);
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
            'accessControlOverrideUser' => $this->getAccessControlOverrideUser($request),
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

    private function getSourceForPath(string $path, array $accessControlInfo): DirectorySource
    {
        // TODO
        return new DirectorySource($path, $accessControlInfo);
    }

    private function getAccessControlOverrideUser(Request $request): ?User
    {
        /** @var User $user */
        $user = $request->user();
        if (!$user->is_admin) {
            return null;
        }

        $impersonatingUserId = $request->session()->get('admin_impersonating_user');
        if ($impersonatingUserId === null) {
            return null;
        }

        return User::find($impersonatingUserId);
    }
}

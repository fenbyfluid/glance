<?php

namespace App\Http\Controllers;

use App\Media\Sources\DirectorySource;
use App\Utilities\Path;
use Illuminate\Http\Response;
use Illuminate\View\View;

class MediaController extends Controller
{
    public function index(string $path = ''): View|Response
    {
        $filesystemPath = config('media.path').'/'.Path::resolve($path);

        if (!file_exists($filesystemPath)) {
            abort(404);
        }

        if (is_file($filesystemPath)) {
            return response(null, 204, [
                'X-Accel-Redirect' => '/internal-media/'.$path,
            ]);
        }

        return view('media.index', [
            'path' => $path,
            'breadcrumbs' => $this->getCrumbsForPath($path),
            'contents' => $this->getPathContents($filesystemPath),
        ]);
    }

    private function getCrumbsForPath(string $path): array
    {
        $crumbs = [];

        $running = null;
        $crumbs[] = (object) [
            'label' => '',
            'path' => '',
        ];

        foreach (explode('/', $path) as $crumb) {
            if ($running === null) {
                $running = $crumb;
            } else {
                $running .= '/'.$crumb;
            }

            $crumbs[] = (object) [
                'label' => $crumb,
                'path' => $running,
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

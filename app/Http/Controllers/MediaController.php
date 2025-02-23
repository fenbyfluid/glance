<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class MediaController extends Controller
{
    public function index(string $path = ''): View
    {
        return view('media.index', [
            'path' => $path,
            'breadcrumbs' => $this->pathToCrumbs($path),
        ]);
    }

    private function pathToCrumbs(string $path): array
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
}

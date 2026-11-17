<?php

use App\Utilities\Path;

if (!function_exists('media_url')) {
    function media_url(string $path, string $route = 'dashboard'): string
    {
        $exploded = explode('/', $path);
        $resolved = Path::resolve($exploded);
        $recombined = implode('/', $resolved);

        $url = route($route, ['path' => $recombined], false);
        if (str_ends_with($path, '/') && !str_ends_with($url, '/')) {
            $url .= '/';
        }

        return $url;
    }
}

if (!function_exists('media_info_url')) {
    function media_info_url(string $path): string
    {
        return media_url(rtrim($path, '/'), 'media.info');
    }
}

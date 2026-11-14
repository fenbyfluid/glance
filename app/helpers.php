<?php

use App\Utilities\Path;

if (!function_exists('media_url')) {
    function media_url(string $path): string
    {
        $exploded = explode('/', $path);
        $resolved = Path::resolve($exploded);
        $recombined = implode('/', $resolved);

        $url = route('dashboard', ['path' => $recombined], false);
        if (str_ends_with($path, '/') && !str_ends_with($url, '/')) {
            $url .= '/';
        }

        return $url;
    }
}

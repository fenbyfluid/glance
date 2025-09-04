<?php

namespace App\Utilities;

class Path
{
    /**
     * Resolves an input like "/foo/../bar/./bee/" to "bar/bee".
     * Attempts to navigate above the root directory will be dropped.
     *
     * @template T of string|string[]
     *
     * @param  T  $path
     * @return T
     */
    public static function resolve(string|array $path): string|array
    {
        $exploded = $path;
        if (!is_array($exploded)) {
            $exploded = explode('/', $path);
        }

        $resolved = [];
        foreach ($exploded as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                // If this returns null, an attempt was made to navigate above the root directory.
                array_pop($resolved);

                continue;
            }

            $resolved[] = $part;
        }

        if (is_array($path)) {
            return $resolved;
        } else {
            return implode('/', $resolved);
        }
    }

    public static function makeThumbnailPath(string $id, string $extension = 'avif'): string
    {
        $basePath = config('media.path').'/.thumbs';
        $subDirs = substr($id, 0, 4).'/'.substr($id, 4, 4);

        if (!is_dir($basePath.'/'.$subDirs)) {
            if (!@mkdir($basePath.'/'.$subDirs, 0755, true)) {
                throw new \RuntimeException('Unable to create thumbnail directory '.$subDirs);
            }
        }

        return $basePath.'/'.$subDirs.'/'.$id.'.'.$extension;
    }
}

<?php

namespace App\Utilities;

class Path
{
    /**
     * Resolves an input like "/foo/../bar/./bee/" to "bar/bee".
     * Attempts to navigate above the root directory will be dropped.
     */
    public static function resolve(string $path): string
    {
        $exploded = explode('/', $path);

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

        return implode('/', $resolved);
    }
}

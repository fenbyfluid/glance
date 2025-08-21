<?php

namespace App\Utilities;

use Symfony\Component\Mime\MimeTypeGuesserInterface;

class OverrideMimeTypeGuesser implements MimeTypeGuesserInterface
{
    public function isGuesserSupported(): bool
    {
        return true;
    }

    public function guessMimeType(string $path): ?string
    {
        // CSS files must be served as text/css, but they get detected as text/plain.
        if (str_ends_with($path, '.css')) {
            return 'text/css';
        }

        return null;
    }
}

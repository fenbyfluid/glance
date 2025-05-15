<?php

namespace App\Media;

use App\Jobs\GenerateFilePerceptualHashJob;
use App\Utilities\Deferred;

// Brilliantly, this is as fast as the native Go program
readonly class VideoPerceptualHasher
{
    // TODO: Move this to a new home when we make a plan for OpenSubtitlesHasher too
    public function hash(string $path): string
    {
        $hash = new Deferred;

        GenerateFilePerceptualHashJob::jobChainForVideoFile($hash, $path)
            ->onConnection('sync')
            ->dispatch();

        return $hash->block();
    }
}

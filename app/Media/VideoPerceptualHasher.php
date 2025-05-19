<?php

namespace App\Media;

use App\Jobs\GenerateMediaFilePerceptualHashJob;
use App\Utilities\Deferred;
use Illuminate\Support\Facades\Bus;

// Brilliantly, this is as fast as the native Go program
readonly class VideoPerceptualHasher
{
    // TODO: Move this to a new home when we make a plan for OpenSubtitlesHasher too
    public function hash(string $path): string
    {
        $hash = new Deferred;

        Bus::chain([
            new GenerateMediaFilePerceptualHashJob($hash, $path),
        ])->onConnection('sync')->dispatch();

        return $hash->block();
    }
}

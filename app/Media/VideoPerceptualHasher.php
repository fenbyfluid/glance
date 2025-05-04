<?php

namespace App\Media;

use App\Utilities\Math;

// Brilliantly, this is as fast as the native Go program
// TODO: Add an .env option to optionally use the native phasher binary for bit exact hashes
readonly class VideoPerceptualHasher
{
    private const int FRAME_WIDTH = 160;

    public function hash(string $path): string
    {
        $mediaFile = new MediaFile($path);
        $mediaInfo = $mediaFile->probe();

        // Round the duration to match Stash
        $duration = round($mediaInfo->duration, 2);

        // TODO: There is a proposal in Stash to set the number of frames used based on the video duration:
        //       https://github.com/stashapp/stash/pull/4074, <=45s 2x2, <=90s 3x3, <=150s 4x4, else 5x5
        // TODO: We're also interested in hashing images, where we probably want to share the hashSpriteImage logic.
        $gridSize = 5;

        // TODO: Re-work this to dispatch a batch of queue jobs, one per tile, then chain another to combine them.
        //       This probably won't be as efficient as our fancy concurrency, but it'll be more standard code and
        //       it'll work better with Laravel's job scheduling not liking long-running jobs.
        $spriteImage = $mediaFile->captureTiledFrameImage(
            $duration * 0.05,
            ($duration * 0.9) / ($gridSize * $gridSize),
            $gridSize,
            $gridSize * $gridSize,
            self::FRAME_WIDTH,
            -2,
        );

        return $this->hashSpriteImage($spriteImage);
    }

    private function hashSpriteImage(\GdImage $image): string
    {
        // TODO: This resize implementation is the only thing not quite correct vs Stash's phasher binary.
        //       It seems to be that goimagehasher's "Bilinear" resize method isn't actually standard bilinear.
        //       Suspiciously, it produces a very soft output, whereas bilinear scaling down is typically overly sharp.
        //       We can get within 2-8 hamming distance of the expected hash by using a gaussian resize method instead.
        //       We mustn't submit hashes to StashDB unless we can be bit exact.
        $resized = imagescale($image, 64, 64, IMG_GAUSSIAN);
        if ($resized === false) {
            throw new \RuntimeException('Unable to resize image');
        }

        // abort(response()->stream(function () use ($resized) {
        //     imagebmp($resized);
        // }, 200, [
        //     'Content-Type' => 'image/bmp',
        // ]));

        // Everything below here is bit exact to Stash's phasher binary

        $pixels = [];
        for ($y = 0; $y < 64; $y++) {
            $pixels[$y] = [];

            for ($x = 0; $x < 64; $x++) {
                $argb = imagecolorat($resized, $x, $y);
                $r = ($argb >> 16) & 0xFF;
                $g = ($argb >> 8) & 0xFF;
                $b = $argb & 0xFF;

                $pixels[$y][$x] = (0.299 * $r) + (0.587 * $g) + (0.114 * $b);
            }
        }

        $flattened = Math::calculateDct2dFast64($pixels);

        $median = Math::calculateMedian($flattened);

        $bits = [];
        foreach ($flattened as $pixel) {
            $bits[] = (int) ($pixel > $median);
        }

        return Math::encodeBitsToHash($bits);
    }
}

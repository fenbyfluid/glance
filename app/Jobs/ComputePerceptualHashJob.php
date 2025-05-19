<?php

namespace App\Jobs;

use App\Models\IndexedFile;
use App\Utilities\Deferred;
use App\Utilities\Math;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\WithoutRelations;

class ComputePerceptualHashJob implements ShouldQueue
{
    use Batchable, Queueable;

    public function __construct(
        public Deferred $inputImage,
        #[WithoutRelations]
        public Deferred|IndexedFile $outputObject,
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $inputImage = imagecreatefromstring($this->inputImage->block());

        $hash = $this->hashImage($inputImage);

        if ($this->outputObject instanceof Deferred) {
            $this->outputObject->set($hash);
        } else {
            $this->outputObject->update([
                'phash' => $hash,
            ]);
        }
    }

    public function hashImage(\GdImage $image): string
    {
        // TODO: This resize implementation is the only thing not quite correct vs Stash's phasher binary.
        //       It seems to be that goimagehasher's "Bilinear" resize method isn't actually standard bilinear.
        //       Suspiciously, it produces a very soft output, whereas bilinear scaling down is typically overly sharp.
        //       We can get within 2-8 hamming distance of the expected hash by using a gaussian resize method instead.
        //       We mustn't submit hashes to StashDB unless we can be bit exact.
        // TODO: We could write a Go binary that took png data on STDIN and only did these steps.
        $resized = imagescale($image, 64, 64, IMG_GAUSSIAN);
        if ($resized === false) {
            throw new \RuntimeException('Unable to resize image');
        }

        // abort(response()->stream(function () use ($resized) {
        //     imagepng($resized);
        // }, 200, [
        //     'Content-Type' => 'image/png',
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

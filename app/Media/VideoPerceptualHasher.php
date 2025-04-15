<?php

namespace App\Media;

use App\Utilities\Math;

// Brilliantly, this is as fast as the native Go program
// TODO: Add an .env option to optionally use the native phasher binary for bitexact hashes
readonly class VideoPerceptualHasher
{
    private const int SPRITE_ROWS = 5;

    private const int SPRITE_COLUMNS = 5;

    private const int FRAME_WIDTH = 160;

    private string $hash;

    public function __construct(
        private string $inputFile,
    ) {}

    public function hash(): string
    {
        if (isset($this->hash)) {
            return $this->hash;
        }

        $mediaFile = new MediaFile($this->inputFile);
        $mediaInfo = $mediaFile->probe();

        // Round the duration to match Stash
        $duration = round($mediaInfo->duration, 2);

        $totalFrames = self::SPRITE_COLUMNS * self::SPRITE_ROWS;
        $spriteImage = $mediaFile->captureTiledFrameImage(
            $duration * 0.05,
            ($duration * 0.9) / $totalFrames,
            self::SPRITE_COLUMNS,
            $totalFrames,
            self::FRAME_WIDTH,
            -2,
        );

        $this->hash = $this->hashSpriteImage($spriteImage);

        return $this->hash;
    }

    private function hashSpriteImage(\GdImage $image): string
    {
        // TODO: This resize implementation is the only thing not quite correct vs Stash's phasher binary.
        //       It seems to be that goimagehasher's "Bilinear" resize method isn't actually standard bilinear.
        //       Suspiciously, it produces a very soft output, whereas bilinear scaling down is typically overly sharp.
        //       We can get within 2-8 hamming distance of the expected hash by using a gaussian resize method instead.
        $resized = imagescale($image, 64, 64, IMG_GAUSSIAN);
        if ($resized === false) {
            throw new \RuntimeException('Unable to resize image');
        }

        // abort(response()->stream(function () use ($resized) {
        //     imagebmp($resized);
        // }, 200, [
        //     'Content-Type' => 'image/bmp',
        // ]));

        // Everything below here is bitexact to Stash's phasher binary

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

        return Math::encodeHash($bits);
    }
}

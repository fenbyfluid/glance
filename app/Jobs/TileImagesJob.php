<?php

namespace App\Jobs;

use App\Utilities\Deferred;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class TileImagesJob implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * @param  list<Deferred>  $inputImages
     */
    public function __construct(
        public array $inputImages,
        public int $columns,
        public Deferred $outputImage,
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            $this->outputImage->set(new \RuntimeException('Batch canceled'));

            return;
        }

        $outputImage = null;

        foreach ($this->inputImages as $i => $deferredImage) {
            $image = imagecreatefromstring($deferredImage->block());
            $width = imagesx($image);
            $height = imagesy($image);

            if ($outputImage === null) {
                $yTotal = (int) ceil(count($this->inputImages) / $this->columns);
                $xTotal = ($yTotal > 1) ? $this->columns : count($this->inputImages);
                $outputImage = imagecreatetruecolor($xTotal * $width, $yTotal * $height);
                if ($outputImage === false) {
                    throw new \RuntimeException('Unable to create output image');
                }
            }

            $x = $i % $this->columns;
            $y = (int) floor($i / $this->columns);
            if (!imagecopy($outputImage, $image, $x * $width, $y * $height, 0, 0, $width, $height)) {
                throw new \RuntimeException('Unable to copy image');
            }
        }

        ob_start();
        imagepng($outputImage);
        $outputData = ob_get_contents();
        ob_end_clean();

        $this->outputImage->set($outputData);
    }

    public function failed(?\Throwable $exception): void
    {
        $this->outputImage->set($exception ?? new \RuntimeException('Unknown failure'));
    }
}

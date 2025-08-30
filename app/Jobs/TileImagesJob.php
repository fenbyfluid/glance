<?php

namespace App\Jobs;

use App\Utilities\Deferred;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Jcupitt\Vips;

class TileImagesJob implements ShouldQueue
{
    use Batchable, Queueable;

    public bool $ignoreMissingImages = false;

    /**
     * @param  list<Deferred>  $inputImages
     */
    public function __construct(
        public array $inputImages,
        public int $columns,
        public Deferred|string $outputImage,
    ) {}

    public function ignoreMissingImages(bool $ignoreMissingImages = true): static
    {
        $this->ignoreMissingImages = $ignoreMissingImages;

        return $this;
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            if ($this->outputImage instanceof Deferred) {
                $this->outputImage->set(new \RuntimeException('Batch canceled'));
            }

            return;
        }

        if ($this->outputImage instanceof Deferred) {
            $this->handleDeferredOutput();
        } else {
            $this->handleFileOutput();
        }
    }

    public function failed(?\Throwable $exception): void
    {
        if ($this->outputImage instanceof Deferred) {
            $this->outputImage->set($exception ?? new \RuntimeException('Unknown failure'));
        }
    }

    private function handleDeferredOutput(): void
    {
        $outputImage = null;
        $lastInputImage = null;

        foreach ($this->inputImages as $i => $deferredImage) {
            try {
                $imageData = $deferredImage->block();
                $lastInputImage = imagecreatefromstring($imageData);
            } catch (\Exception $e) {
                if (!$this->ignoreMissingImages) {
                    throw $e;
                }

                if (!$lastInputImage) {
                    continue;
                }
            }

            $image = $lastInputImage;

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

        // Oddly, GD's PNG compression seems better than Vips, and we OOM with Vips.
        ob_start();
        imagepng($outputImage);
        $outputData = ob_get_clean();

        $this->outputImage->set($outputData);
    }

    private function handleFileOutput(): void
    {
        Cache::lock('vips', 30)->block(90, function () {
            Vips\Config::cacheSetMax(0);
            Vips\Config::concurrencySet(1);

            $inputImages = [];
            $lastInputImage = null;
            foreach ($this->inputImages as $deferredImage) {
                try {
                    $imageData = $deferredImage->block();
                    $lastInputImage = Vips\Image::pngload_buffer($imageData);
                } catch (\Exception $e) {
                    if (!$this->ignoreMissingImages) {
                        throw $e;
                    }
                }

                $inputImages[] = $lastInputImage ?: Vips\Image::black(1, 1);
            }

            $outputImage = Vips\Image::arrayjoin($inputImages, [
                'across' => $this->columns,
            ]);

            $outputImage->writeToFile($this->outputImage);
        });
    }
}

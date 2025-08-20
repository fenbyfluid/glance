<?php

namespace App\Jobs;

use App\Media\MediaContentKind;
use App\Models\IndexedFile;
use App\Utilities\Path;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\WithoutRelations;
use Illuminate\Support\Str;
use Jcupitt\Vips;

class GenerateMediaFileThumbnailJob implements ShouldQueue
{
    use Batchable, Queueable;

    public function __construct(
        #[WithoutRelations]
        public IndexedFile $outputObject,
        public string $path,
        public int $frameCaptureMs,
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $path = $this->path;
        if (!str_starts_with($path, '/')) {
            $path = config('media.path').'/'.$path;
        }

        $imageId = Str::uuid()->toString();
        $imagePath = Path::makeThumbnailPath($imageId);

        // TODO: Test this properly.
        // TODO: This should be a separate job as it should be on the ffmpeg-capture queue.
        if ($this->outputObject->kind === MediaContentKind::Image) {
            Vips\Config::cacheSetMax(0);
            Vips\Config::concurrencySet(1);

            $image = Vips\Image::thumbnail($path, 320, [
                'height' => 180,
                'output_profile' => 'srgb',
            ]);

            $image->writeToFile($imagePath);

            $this->outputObject->update([
                'thumbnail' => $imageId,
            ]);

            return;
        }

        $frameTime = null;
        if ($this->outputObject->kind === MediaContentKind::Video) {
            $durationMs = $this->outputObject->media_info?->durationMs;
            if ($durationMs !== null && $this->frameCaptureMs < $durationMs) {
                // TODO: If this fails, we should retry with no seek.
                $frameTime = $this->frameCaptureMs / 1000;
            }
        }

        $this->appendToChain((new CaptureFrameJob($path, $frameTime, 320, 180, $imagePath))->onQueue('ffmpeg-capture'));

        $outputObject = $this->outputObject;
        $this->appendToChain(static function () use ($imageId, $outputObject) {
            $outputObject->update([
                'thumbnail' => $imageId,
            ]);
        })->onQueue('generation-hash');
    }
}

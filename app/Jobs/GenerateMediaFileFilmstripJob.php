<?php

namespace App\Jobs;

use App\Models\IndexedFile;
use App\Utilities\Deferred;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\WithoutRelations;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

class GenerateMediaFileFilmstripJob implements ShouldQueue
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

        $durationMs = $this->outputObject->media_info?->durationMs;
        if (!isset($durationMs)) {
            throw new \RuntimeException('Missing video duration for '.$path);
        }

        $startTime = 0.0;
        if ($this->frameCaptureMs < $durationMs) {
            $startTime = $this->frameCaptureMs / 1000;
        }

        // TODO: Tune this for shorter files. Our old code used to just step by time.
        $gridSize = 10;
        $frameCount = $gridSize * 10;
        $stepTime = (($durationMs / 1000) - ($startTime * 2)) / $frameCount;

        $frameImages = [];
        $frameCaptureJobs = [];
        for ($i = 0; $i < $frameCount; $i++) {
            $frameImage = new Deferred;
            $frameImages[] = $frameImage;
            $frameCaptureJobs[] = new CaptureFrameJob($path, $startTime + ($stepTime * $i), 1280, 720, $frameImage);
        }

        $imageId = Str::uuid();

        // TODO: Decide where we want to store these.
        $imagePath = sprintf('%s/.thumbs/%s.avif', config('media.path'), $imageId->toString());

        $this->appendToChain((new WaitForFrameCaptureSlot)->onQueue('ffmpeg-wait'));
        $this->appendToChain(Bus::batch($frameCaptureJobs)->name('Capturing '.$path)->onQueue('ffmpeg-capture'));
        $this->appendToChain((new TileImagesJob($frameImages, $gridSize, $imagePath))->onQueue('generation-tile'));

        $outputObject = $this->outputObject;
        $this->appendToChain(static function () use ($imageId, $outputObject) {
            $outputObject->update([
                'filmstrip' => $imageId,
            ]);
        })->onQueue('generation-hash');
    }
}

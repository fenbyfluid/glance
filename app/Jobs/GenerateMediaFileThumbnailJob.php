<?php

namespace App\Jobs;

use App\Media\MediaContentKind;
use App\Models\IndexedFile;
use App\Utilities\Deferred;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\WithoutRelations;
use Illuminate\Support\Str;

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

        $frameTime = null;
        if ($this->outputObject->kind === MediaContentKind::Video) {
            $durationMs = $this->outputObject->media_info?->durationMs;
            if ($durationMs !== null && $this->frameCaptureMs < $durationMs) {
                $frameTime = $this->frameCaptureMs / 1000;
            }
        }

        $frameImage = new Deferred;

        $this->appendToChain((new CaptureFrameJob($path, $frameTime, 320, 180, $frameImage))->onQueue('ffmpeg-capture'));

        $outputObject = $this->outputObject;
        $this->appendToChain(static function () use ($frameImage, $outputObject) {
            $imageId = Str::uuid();

            // TODO: Decide where we want to store these.
            $imagePath = sprintf('%s/.thumbs/%s.avif', config('media.path'), $imageId->toString());

            $image = imagecreatefromstring($frameImage->block());
            if (!imageavif($image, $imagePath)) {
                throw new \RuntimeException('Failed to write thumbnail');
            }

            $outputObject->update([
                'thumbnail' => $imageId,
            ]);
        })->onQueue('generation-hash');
    }
}

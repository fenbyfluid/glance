<?php

namespace App\Jobs;

use App\Media\MediaContentKind;
use App\Media\MediaInfo;
use App\Models\IndexedFile;
use App\Utilities\Deferred;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\WithoutRelations;
use Illuminate\Support\Facades\Bus;

class GenerateMediaFilePerceptualHashJob implements ShouldQueue
{
    use Batchable, Queueable;

    public function __construct(
        #[WithoutRelations]
        public Deferred|IndexedFile $outputObject,
        public string $path,
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

        if ($this->outputObject instanceof Deferred) {
            // This rather unpleasant path exists only for our app:hash command, so it doesn't need to be the best.
            $mimeType = mime_content_type($path);
            $kind = MediaContentKind::guessForFile($mimeType, strrchr(basename($this->path), '.'));

            if ($kind === MediaContentKind::Video) {
                $mediaInfo = MediaInfo::probeFile($path);
                $durationMs = $mediaInfo->durationMs;
            }
        } else {
            $kind = $this->outputObject->kind;

            if ($kind === MediaContentKind::Video) {
                $durationMs = $this->outputObject->media_info?->durationMs;
            }
        }

        switch ($kind) {
            case MediaContentKind::Image:
                $jobs = self::prepareJobsForImageFile($this->outputObject, $path);
                break;
            case MediaContentKind::Video:
                if (!isset($durationMs)) {
                    throw new \RuntimeException('Missing video duration for '.$path);
                }

                $jobs = self::prepareJobsForVideoFile($this->outputObject, $path, $durationMs);
                break;
            default:
                throw new \LogicException('Unsupported kind: '.$kind);
        }

        // Laravel has some non-intuitive behaviour where the queue worker only picks up jobs from a different priority
        // queue once the queue it is currently working on has become exhausted. This means that we *must* dispatch a
        // job to a lower-priority queue than ourselves, otherwise higher-priority jobs we dispatch (either directly or
        // via chaining) do not preempt our sibling jobs being run, resulting in the capture queue becoming overloaded.
        // To get around this, we're dispatched on the index queue directly (as we're cheap), and we start the chain
        // with a job on the lowest priority queue to control the processing throughput. The job itself is a no-op.
        $this->appendToChain((new WaitForFrameCaptureSlot)->onQueue('ffmpeg-wait'));

        // We append all the jobs to the current chain for the benefit of our app:hash command using sync.
        foreach ($jobs as $job) {
            $this->appendToChain($job);
        }
    }

    private static function prepareJobsForImageFile(Deferred|IndexedFile $outputObject, string $path): array
    {
        $frameImage = new Deferred;

        return [
            // We could load most images directly into Gd, but let's assume FFmpeg is better hardened
            // against untrustworthy inputs and keep this aligned with the video pathway.
            (new CaptureFrameJob($path, null, 160, -2, $frameImage))->onQueue('ffmpeg-capture'),
            (new ComputePerceptualHashJob($frameImage, $outputObject))->onQueue('generation-hash'),
        ];
    }

    private static function prepareJobsForVideoFile(Deferred|IndexedFile $outputObject, string $path, int $durationMs): array
    {
        // Round the duration to match Stash
        $duration = round($durationMs / 1000, 2);

        // TODO: There is a proposal in Stash to set the number of frames used based on the video duration:
        //       https://github.com/stashapp/stash/pull/4074, <=45s 2x2, <=90s 3x3, <=150s 4x4, else 5x5
        //       We don't really care about matching short scenes with StashDB, so use the better algorithm.
        $gridSize = match (true) {
            $duration <= 45 => 2,
            $duration <= 90 => 3,
            $duration <= 120 => 4,
            default => 5,
        };

        $startTime = $duration * 0.05;
        $frameCount = $gridSize * $gridSize;
        $stepTime = ($duration * 0.9) / $frameCount;

        // TODO: One of our gripes with our implementation is the inability to perfectly match Stash due
        //       to difference in resizing algorithms. It also feels somewhat silly that it generates a
        //       grid of 160px wide frame images (preserving aspect ratio) to then squash it into a 64px
        //       square output image. Unfortunately, due to grid size not being a common factor of 64, we
        //       can't just get FFmpeg to generate images of the right size itself. It would be very useful
        //       to re-specify this so that it's something like 1x1 (1), 1x2 (2), 2x2 (4), 2x4 (8), 4x4 (16),
        //       4x8 (32). This would allow us to, for example for the 2x4 case, have FFmpeg output 8 32x16px
        //       frames and then stitch them together losslessly to produce the 64x64px sprite for hashing.
        //       Unfortunately, I suspect 12px square (the current 5x5) is a bit of a sweet spot, so it's a
        //       shame the next step up from there is 16x8px. I'm not sure how the DCT algorithm would
        //       behave if we did do 12x12px and just left a black border in the bottom right? Speaking of
        //       which, need to understand if different number of tiles per dimension is algorithmically
        //       sound before proposing this - it's no good if the DCT relies on them being the same.

        $frameImages = [];
        $frameCaptureJobs = [];
        for ($i = 0; $i < $frameCount; $i++) {
            $frameImage = new Deferred;
            $frameImages[] = $frameImage;
            $frameCaptureJobs[] = new CaptureFrameJob($path, $startTime + ($stepTime * $i), 160, -2, $frameImage);
        }

        $tileImage = new Deferred;

        // TODO: One downside of this design is all the compressed images have to be buffered in the cache
        //       before being fetched and combined by TileImagesJob. One way around that would be to build
        //       up very large tiled images in multiple steps by feeding TileImagesJobs into TileImagesJob
        //       via multiple batches in a chain. Our current implementation can't quite handle that in
        //       general usage yet as all inputs to TileImagesJob are currently assumed to be the same
        //       dimensions, so the last batch may end up padded if each tile is more than one row.
        //       Have tried the design proposed here and it does work, but it's not very useful as it takes
        //       much longer to complete the chain, and the final TileImagesJobs still has to hold all of
        //       the image data in memory. But, it does avoid the cache pressure, if that was a problem.

        return [
            Bus::batch($frameCaptureJobs)->onQueue('ffmpeg-capture'),
            (new TileImagesJob($frameImages, $gridSize, $tileImage))->onQueue('generation-tile'),
            (new ComputePerceptualHashJob($tileImage, $outputObject))->onQueue('generation-hash'),
        ];
    }
}

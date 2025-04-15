<?php

namespace App\Media;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

readonly class MediaFile
{
    private const string FFPROBE_LOG_LEVEL = 'warning';

    private const string FFMPEG_LOG_LEVEL = 'fatal';

    private const int FRAME_CAPTURE_TIMEOUT = 30;

    public function __construct(
        private string $path,
    ) {}

    /**
     * @throws \JsonException
     * @throws \RuntimeException
     */
    public function probe(): MediaInfo
    {
        // TODO: show_packets + json_decode can run out of memory when parsing.
        //       Use metadata duration for now, if we start having trouble it's probably easiest to probe again as CSV.
        $process = Process::env([
            'AV_LOG_FORCE_NOCOLOR' => '1',
        ])->run([
            config('media.ffprobe_bin', '/usr/bin/ffprobe'),
            '-loglevel',
            '+repeat+level+'.self::FFPROBE_LOG_LEVEL,
            '-hide_banner',
            '-output_format',
            'json=compact=1',
            '-show_format',
            '-show_streams',
            // '-show_packets',
            '-show_error',
            '-show_entries',
            'stream_side_data=rotation',
            $this->path,
        ]);

        $output = $process->output();
        if (str_starts_with($output, '{') && str_ends_with(rtrim($output), '}')) {
            $output = json_decode($output, flags: JSON_THROW_ON_ERROR);
        } else {
            $output = null;
        }

        $logs = $process->errorOutput();

        // TODO: Refine this error handling.
        if ($process->failed()) {
            if (isset($output->error->string)) {
                throw new \RuntimeException(sprintf('FFprobe failed with code %d: %s',
                    $output->error->code ?? 0, $output->error->string));
            } else {
                throw new \RuntimeException(sprintf('FFprobe failed with code %d:%s%s',
                    $process->exitCode(), PHP_EOL, $logs));
            }
        }

        if (strlen($logs) > 0) {
            Log::debug('FFprobe output for "'.$this->path.'":'.PHP_EOL.$logs);
        }

        return MediaInfo::fromProbeOutput($output);
    }

    public function captureFrameImage(float $time, int $width, int $height): \GdImage
    {
        $captureFrameImagePromise = $this->startCaptureFrameImage($time, $width, $height);

        return $captureFrameImagePromise(true);
    }

    public function captureTiledFrameImage(float $startOffset, float $stepOffset, int $framesWide, int $totalFrames, int $captureFrameWidth, int $captureFrameHeight, int $concurrency = 1)
    {
        $pendingFrames = [];
        $combinedImage = null;

        // $concurrency = 1: ~6s, 2: ~3.75s, 4: ~2.75s, 8: ~2.5s, 16: ~2.5s, 32: ~2.5s
        // A concurrency of 1 with this impl has identical or better performance to
        // the native process waiting without this queue management "overhead".
        // This applied before we added the wait option to the "promise" too.
        $runPending = function (bool $flushing) use (&$pendingFrames, $concurrency) {
            while (true) {
                // Update the status and remove any completed tasks from the queue.
                foreach ($pendingFrames as $i => $pendingFrame) {
                    if (!$pendingFrame($flushing || $concurrency === 1)) {
                        unset($pendingFrames[$i]);
                    }
                }

                // All done.
                if (count($pendingFrames) === 0) {
                    break;
                }

                // Let more work into the queue up to the concurrency limit.
                if (!$flushing && count($pendingFrames) < $concurrency) {
                    break;
                }

                usleep(1000);
            }
        };

        for ($i = 0; $i < $totalFrames; $i++) {
            $runPending(false);

            $x = $i % $framesWide;
            $y = (int) floor($i / $framesWide);

            $frameOffset = $startOffset + ($stepOffset * $i);
            $frameImagePromise = $this->startCaptureFrameImage($frameOffset, $captureFrameWidth, $captureFrameHeight);

            // Returns true if there is still work pending.
            $pendingFrames[] = function () use ($framesWide, $totalFrames, $x, $y, $frameImagePromise, &$combinedImage) {
                $frameImage = $frameImagePromise();
                if ($frameImage === null) {
                    // Not ready yet, try again later.
                    return true;
                }

                $actualFrameWidth = imagesx($frameImage);
                $actualFrameHeight = imagesy($frameImage);

                if ($combinedImage === null) {
                    $totalHeight = $actualFrameHeight * (int) ceil($totalFrames / $framesWide);
                    $totalWidth = ($totalHeight > 1) ? ($actualFrameWidth * $framesWide) : $totalFrames;
                    $combinedImage = imagecreatetruecolor($totalWidth, $totalHeight);
                    if ($combinedImage === false) {
                        throw new \Exception('Unable to create image');
                    }
                }

                $targetX = $actualFrameWidth * $x;
                $targetY = $actualFrameHeight * $y;
                if (!imagecopy($combinedImage, $frameImage, $targetX, $targetY, 0, 0, $actualFrameWidth, $actualFrameHeight)) {
                    throw new \Exception('Unable to copy image');
                }

                return false;
            };
        }

        $runPending(true);

        return $combinedImage;
    }

    /**
     * @return callable(bool=): (\GdImage|null)
     */
    private function startCaptureFrameImage(float $time, int $width, int $height): callable
    {
        $imageData = '';

        // The original implementation of this used the select and tile filters to do it in one shot,
        // but it turned out to have extremely high resource usage with longer videos due to needing
        // to decode all frames sequentially. Seeking the input is much faster overall than avoiding
        // the overhead of multiple process executions and combining the image manually.
        $process = Process::env([
            'AV_LOG_FORCE_NOCOLOR' => '1',
        ])->timeout(self::FRAME_CAPTURE_TIMEOUT)->quietly()->start([
            config('media.ffmpeg_bin', '/usr/bin/ffmpeg'),
            '-loglevel',
            '+repeat+level+'.self::FFMPEG_LOG_LEVEL,
            '-hide_banner',
            '-nostats',
            '-fflags', '+genpts',
            '-ss', sprintf('%f', $time),
            '-i', $this->path,
            '-filter:v', sprintf('scale=%d:%d', $width, $height),
            '-frames:v', '1',
            '-c:v', 'bmp',
            '-f', 'rawvideo',
            '-',
        ], function ($type, $buffer) use (&$imageData) {
            if ($type === 'out') {
                $imageData .= $buffer;

                return;
            }

            foreach (explode(PHP_EOL, $buffer) as $line) {
                $line = rtrim($line);
                if ($line !== '') {
                    Log::debug('[ffmpeg-frame] '.$line);
                }
            }
        });

        // TODO: This is a poor Promise, figure out how to switch to real promises
        return function (bool $wait = false) use ($time, $process, &$imageData) {
            if (!$wait && $process->running()) {
                return null;
            }

            $result = $process->wait();
            if ($result->failed()) {
                throw new \RuntimeException(sprintf('Failed to extract frame image at time %f with code %d', $time, $result->exitCode()));
            }

            $image = imagecreatefromstring($imageData);
            if ($image === false) {
                throw new \RuntimeException('Could not parse image data');
            }

            return $image;
        };
    }
}

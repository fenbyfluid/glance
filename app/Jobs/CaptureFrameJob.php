<?php

namespace App\Jobs;

use App\Utilities\Deferred;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class CaptureFrameJob implements ShouldQueue
{
    use Batchable, Queueable;

    public function __construct(
        public string $path,
        public ?float $time,
        public int $width,
        public int $height,
        public Deferred $outputImage,
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            $this->outputImage->set(new \RuntimeException('Batch canceled'));

            return;
        }

        // Have run tests on different image formats supported by both FFmpeg and Gd (bmp, png, webp), and png
        // is by far the best in terms for encoding time / data size tradeoff. For two representative files:
        //   100 iterations of  bmp took 27.275 seconds (0.273 each) and output ( 2073654 | 2764854 ) bytes each
        //   100 iterations of  png took 28.627 seconds (0.286 each) and output (  725851 | 1221408 ) bytes each
        //   100 iterations of webp took 52.543 seconds (0.525 each) and output (  328188 |  601194 ) bytes each

        $imageData = '';
        $result = Process::env([
            'AV_LOG_FORCE_NOCOLOR' => '1',
        ])->timeout(config('media.frame_capture_timeout', 30))->quietly()->run([
            config('media.ffmpeg_bin', '/usr/bin/ffmpeg'),
            '-loglevel',
            '+repeat+level+'.config('media.ffmpeg_loglevel', 'fatal'),
            '-hide_banner',
            '-nostats',
            '-fflags', '+genpts',
            ...(($this->time !== null) ? ['-ss', sprintf('%f', $this->time)] : []),
            '-i', $this->path,
            '-filter:v', sprintf('scale=%d:%d', $this->width, $this->height),
            '-frames:v', '1',
            '-c:v', 'png',
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

        $exitCode = $result->exitCode();
        if ($exitCode !== 0 || strlen($imageData) === 0) {
            $message = sprintf('Failed to extract frame image at time %f with code %d', $this->time, $exitCode);

            throw new \RuntimeException($message);
        }

        $this->outputImage->set($imageData);
    }

    public function failed(?\Throwable $exception): void
    {
        $this->outputImage->set($exception ?? new \RuntimeException('Unknown failure'));
    }
}

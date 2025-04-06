<?php

namespace App\Media;

use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class MediaTranscode
{
    // warning, error, fatal
    private const string FFMPEG_LOG_LEVEL = 'fatal';

    private InvokedProcess $process;

    private int $oldestSegment;

    private int $latestSegment;

    public function __construct(
        private readonly string $outputDirectory,
        private readonly string $inputFile,
        private readonly int $segmentLength,
        private readonly int $initialSegment,
    ) {
        $this->process = Process::env([
            'AV_LOG_FORCE_NOCOLOR' => '1',
        ])->path($this->outputDirectory)->forever()->quietly()->start([
            config('media.ffmpeg_bin', '/usr/bin/ffmpeg'),
            '-loglevel',
            '+repeat+level+'.self::FFMPEG_LOG_LEVEL,
            '-hide_banner',
            '-nostats',
            ...$this->getInputArguments(),
            ...$this->getVideoEncodeArguments(),
            ...$this->getAudioEncodeArguments(),
            ...$this->getOutputArguments(),
            '-',
        ], function ($type, $buffer) {
            if ($type === 'out') {
                $this->onPlaylistOutput($buffer);

                return;
            }

            foreach (explode(PHP_EOL, $buffer) as $line) {
                $line = rtrim($line);
                if ($line !== '') {
                    Log::debug(sprintf('[ffmpeg-%s] %s', basename($this->outputDirectory), $line));
                }
            }
        });

        // Wait for FFmpeg to exit or produce a valid HLS playlist.
        while ($this->process->running() && !isset($this->latestSegment)) {
            usleep(10_000); // 10ms
        }

        // If it ended without a playlist, something failed.
        if (!$this->process->running()) {
            $result = $this->process->wait();
            throw new \RuntimeException(sprintf('Failed to start transcode with code %d', $result->exitCode()));
        }
    }

    public function check(): bool
    {
        if ($this->process->running()) {
            return true;
        }

        $result = $this->process->wait();
        if ($result->failed()) {
            throw new \RuntimeException(sprintf('Transcode ended unexpectedly with code %d', $result->exitCode()));
        }

        return false;
    }

    public function stop(): void
    {
        $this->process->stop();
    }

    /**
     * @return array{int, int}
     */
    public function getSegmentRange(): array
    {
        return [$this->oldestSegment, $this->latestSegment];
    }

    public function removeSegmentsBefore(int $segment): void
    {
        if ($segment <= $this->oldestSegment) {
            return;
        }

        while ($this->oldestSegment < $segment) {
            File::delete(sprintf('%s/%d.ts', $this->outputDirectory, $this->oldestSegment));

            Log::debug(sprintf('Removed segment %d', $this->oldestSegment));

            $this->oldestSegment++;
        }
    }

    private function onPlaylistOutput(string $playlist): void
    {
        $pattern = '/\A#EXTM3U$.*^((?!#)\N+)\.ts$(?:.*^(#EXT-X-ENDLIST)$)?/ms';
        if (preg_match($pattern, $playlist, $matches, PREG_UNMATCHED_AS_NULL) !== 1) {
            Log::error('Unexpected FFmpeg output:'.PHP_EOL.$playlist);

            return;
        }

        if (!isset($this->oldestSegment)) {
            $this->oldestSegment = $this->initialSegment;
        }

        $this->latestSegment = (int) $matches[1];
        // $this->playlistComplete = $matches[2] !== null;

        Log::debug('Latest segment: '.$this->latestSegment);
    }

    private function getInputArguments(): array
    {
        $startOffset = $this->initialSegment * $this->segmentLength;

        return [
            '-fflags', '+genpts',
            '-ss', (string) $startOffset,
            '-i', $this->inputFile,
            '-map_metadata', '-1',
        ];
    }

    private function getVideoEncodeArguments(): array
    {
        $keyFrameExpression = sprintf('expr:gte(t,n_forced*%d)', $this->segmentLength);

        // TODO: Add a non-hwaccel variant.
        return [
            // Process at 5x speed for first 10 seconds, then 1x speed, scale to 1920px wide, at least 30 fps.
            '-filter:v', "sendcmd=c='10.0 realtime speed 1.0',realtime=speed=5.0,hwupload_cuda,scale_npp=w='min(1920,iw)':h=-2,fps='max(30,source_fps)'",
            '-c:v', 'h264_nvenc',
            '-preset:v', 'p7',
            '-tune:v', 'hq',
            '-rc:v', 'vbr',
            '-cq:v', '23',
            '-b:v', '0',
            '-profile:v', 'high',
            '-forced-idr:v', '1',
            '-force_key_frames', $keyFrameExpression,
        ];
    }

    private function getAudioEncodeArguments(): array
    {
        return [
            '-c:a', 'libfdk_aac',
            '-b:a', '128k',
            '-ar', '48000',
        ];
    }

    private function getOutputArguments(): array
    {
        return [
            '-f', 'hls',
            '-hls_segment_filename', '%d.ts',
            '-hls_time', (string) $this->segmentLength,
            '-hls_list_size', '1',
            '-start_number', (string) $this->initialSegment,
            '-hls_flags', '+split_by_time+temp_file',
        ];
    }
}

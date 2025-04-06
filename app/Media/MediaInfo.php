<?php

namespace App\Media;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

readonly class MediaInfo
{
    private const string CODEC_TYPE_VIDEO = 'video';

    private const string CODEC_TYPE_AUDIO = 'audio';

    private const bool USE_ACCURATE_DURATION = false;

    public float $duration;

    /**
     * @param object{
     *     packets: list<object{
     *         codec_type: string,
     *         stream_index: int,
     *         pts: int,
     *         pts_time: string,
     *         dts: int,
     *         dts_time: string,
     *         duration?: int,
     *         duration_time?: string,
     *         flags: string,
     *     }>,
     *     streams: list<object{
     *         index: int,
     *         codec_name: string,
     *         codec_long_name: string,
     *         codec_type: string,
     *         width?: int,
     *         height?: int,
     *         time_base: string,
     *         start_pts: int,
     *         start_time: string,
     *         duration_ts: int,
     *         duration: string,
     *     }>,
     *     format: object{
     *         filename: string,
     *         format_name: string,
     *         format_long_name: string,
     *         start_time: string,
     *         duration: string,
     *     },
     * } $output
     */
    private function __construct(object $output)
    {
        // This is simply what our old streaming code used to do.
        if (!self::USE_ACCURATE_DURATION) {
            // Media container duration.
            $this->duration = (float) $output->format->duration;

            return;
        }

        // TODO: We could use a bitrate-calculated approximation to decide if we can trust the metadata duration.
        $timingStream = $this->getTimingStream($output->streams);

        if (isset($output->packets)) {
            $lastPacketPts = 0.0;
            $lastPacketDuration = 0.0;
            foreach ($output->packets as $packet) {
                if ($packet->stream_index !== $timingStream->index) {
                    continue;
                }

                if (!isset($packet->pts_time)) {
                    continue;
                }

                $ptsTime = (float) $packet->pts_time;
                if ($ptsTime > $lastPacketPts) {
                    $lastPacketPts = $ptsTime;

                    if (isset($packet->duration_time)) {
                        $lastPacketDuration = (float) $packet->duration_time;
                    }
                }
            }

            // Actual decoded packet duration.
            $this->duration = $lastPacketPts + $lastPacketDuration;
        } else {
            // A/V stream duration.
            $this->duration = (float) $timingStream->duration;
        }
    }

    private function getTimingStream(array $streams): object
    {
        if (count($streams) < 1) {
            throw new \RuntimeException('No streams in media file');
        }

        $timeStream = null;

        foreach ($streams as $stream) {
            if ($stream->codec_type === self::CODEC_TYPE_VIDEO) {
                $timeStream = $stream;
                break;
            }
        }

        if ($timeStream === null) {
            foreach ($streams as $stream) {
                if ($stream->codec_type === self::CODEC_TYPE_AUDIO) {
                    $timeStream = $stream;
                    break;
                }
            }
        }

        if ($timeStream === null) {
            $timeStream = $streams[0];
        }

        return $timeStream;
    }

    /**
     * @throws \JsonException
     * @throws \RuntimeException
     */
    public static function probeFile(string $path): self
    {
        // TODO: show_packets + json_decode can run out of memory when parsing.
        //       Use metadata duration for now, if we start having trouble it's probably easiest to probe again as CSV.
        $process = Process::env([
            'AV_LOG_FORCE_NOCOLOR' => '1',
        ])->run([
            config('media.ffprobe_bin', '/usr/bin/ffprobe'),
            '-loglevel',
            '+repeat+level+warning',
            '-hide_banner',
            '-output_format',
            'json=compact=1',
            '-show_format',
            '-show_streams',
            // '-show_packets',
            '-show_error',
            $path,
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
            Log::debug('FFprobe output for "'.$path.'":'.PHP_EOL.$logs);
        }

        return new self($output);
    }
}

<?php

namespace App\Media;

readonly class MediaInfo
{
    private const string CODEC_TYPE_VIDEO = 'video';

    private const string CODEC_TYPE_AUDIO = 'audio';

    private const bool USE_ACCURATE_DURATION = false;

    public function __construct(
        public float $duration,
    ) {}

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
     *         side_data_list?: list<object{
     *             rotation?: int,
     *         }>,
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
    public static function fromProbeOutput(object $output): self
    {
        // This is simply what our old streaming code used to do.
        if (!self::USE_ACCURATE_DURATION) {
            // Media container duration.
            return new self((float) $output->format->duration);
        }

        // TODO: We could use a bitrate-calculated approximation to decide if we can trust the metadata duration.
        $timingStream = self::getTimingStream($output->streams);

        if (!isset($output->packets)) {
            // A/V stream duration.
            return new self((float) $timingStream->duration);
        }

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
        return new self($lastPacketPts + $lastPacketDuration);
    }

    private static function getTimingStream(array $streams): object
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
}

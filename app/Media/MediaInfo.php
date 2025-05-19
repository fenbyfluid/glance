<?php

namespace App\Media;

use App\Casts\AsMediaInfo;
use App\Media\MediaInfo\StreamInfo;
use App\Utilities\ArrayableWithoutNullValues;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

readonly class MediaInfo implements Arrayable, Castable
{
    use ArrayableWithoutNullValues;

    /** @var list<StreamInfo> */
    public array $streams;

    public ?int $durationMs;

    public ?int $bitRate;

    // From tags:
    public ?string $title;

    public ?string $comment;

    public ?int $encodingTimestamp;

    public static function castUsing(array $arguments): string
    {
        return AsMediaInfo::class;
    }

    public static function fromArray(array $data): self
    {
        $self = new self;
        $self->streams = array_map(StreamInfo::fromArray(...), $data['streams'] ?? []);
        $self->durationMs = $data['durationMs'] ?? null;
        $self->bitRate = $data['bitRate'] ?? null;
        $self->title = $data['title'] ?? null;
        $self->comment = $data['comment'] ?? null;
        $self->encodingTimestamp = $data['encodingTimestamp'] ?? null;

        return $self;
    }

    /**
     * @param array{
     *     packets: list<array{
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
     *     streams: list<array{
     *         index: int,
     *         codec_name: string,
     *         codec_long_name: string,
     *         profile?: string,
     *         codec_type: string,
     *         width?: int,
     *         height?: int,
     *         pix_fmt?: string,
     *         sample_rate?: string,
     *         channels?: int,
     *         channel_layout?: string,
     *         level?: int,
     *         r_frame_rate?: string,
     *         avg_frame_rate?: string,
     *         time_base: string,
     *         start_pts: int,
     *         start_time: string,
     *         duration_ts?: int,
     *         duration?: string,
     *         bit_rate?: string,
     *         side_data_list?: list<array{
     *             rotation?: int,
     *         }>,
     *         tags?: array<string, string>,
     *     }>,
     *     format: array{
     *         filename: string,
     *         format_name: string,
     *         format_long_name: string,
     *         start_time: string,
     *         duration?: string,
     *         bit_rate?: string,
     *         tags?: array<string, string>,
     *     },
     * } $data
     */
    public static function fromProbeOutput(array $data): self
    {
        $formatTags = $data['format']['tags'] ?? [];

        $encodingTimestamp = null;
        $utcTimezone = new DateTimeZone('UTC');
        if (isset($formatTags['creation_time'])) {
            // "creation_time": "2022-12-18T00:59:37.000000Z"
            $encodingTimestamp = DateTimeImmutable::createFromFormat(
                'Y-m-d\TH:i:s.up', $formatTags['creation_time'], $utcTimezone)->getTimestamp();
        } elseif (isset($formatTags['metadatadate'])) {
            // "metadatadate": "2011-02-02T09:37:38.502000Z"
            $encodingTimestamp = DateTimeImmutable::createFromFormat(
                'Y-m-d\TH:i:s.up', $formatTags['metadatadate'], $utcTimezone)->getTimestamp();
        } elseif (isset($formatTags['Creation Date'])) {
            // "Creation Date": "8/14/2005 19:30:39"
            $encodingTimestamp = DateTimeImmutable::createFromFormat(
                'n/j/Y H:i:s', $formatTags['Creation Date'], $utcTimezone)->getTimestamp();
        }

        return self::fromArray([
            'streams' => array_map(MediaInfo\StreamInfo::fromProbeOutput(...), $data['streams'] ?? []),
            'durationMs' => isset($data['format']['duration']) ? round($data['format']['duration'] * 1000) : null,
            'bitRate' => isset($data['format']['bit_rate']) ? (int) $data['format']['bit_rate'] : null,
            'title' => $formatTags['title'] ?? null,
            'comment' => $formatTags['comment'] ?? null,
            'encodingTimestamp' => $encodingTimestamp,
        ]);
    }

    public static function probeFile(string $path): self
    {
        // TODO: show_packets + json_decode can run out of memory when parsing.
        //       Use metadata duration for now, if we start having trouble it's probably easiest to probe again as CSV.
        //         -output_format csv:p=0
        //         -show_entries 'packet=stream_index,pts,pts_time,dts,dts_time,duration,duration_time,flags'
        $process = Process::env([
            'AV_LOG_FORCE_NOCOLOR' => '1',
        ])->timeout(config('media.ffprobe_timeout', 30))->run([
            config('media.ffprobe_bin', '/usr/bin/ffprobe'),
            '-loglevel', '+repeat+level+'.config('media.ffprobe_loglevel', 'warning'),
            '-hide_banner',
            '-output_format', 'json=compact=1',
            '-show_error',
            '-show_format',
            '-show_streams',
            // '-show_packets',
            '-show_entries', 'stream_side_data=rotation',
            $path,
        ]);

        $output = $process->output();
        if (str_starts_with($output, '{') && str_ends_with(rtrim($output), '}')) {
            $output = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } else {
            $output = null;
        }

        $logs = $process->errorOutput();

        // TODO: Refine this error handling.
        if ($process->failed()) {
            if (isset($output['error']['string'])) {
                throw new \RuntimeException(sprintf('FFprobe failed with code %d: %s',
                    $output['error']['code'] ?? 0, $output['error']['string']));
            } else {
                throw new \RuntimeException(sprintf('FFprobe failed with code %d:%s%s',
                    $process->exitCode(), PHP_EOL, $logs));
            }
        }

        if (strlen($logs) > 0) {
            Log::debug('FFprobe output for "'.$path.'":'.PHP_EOL.$logs);
        }

        return MediaInfo::fromProbeOutput($output);
    }
}

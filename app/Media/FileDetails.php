<?php

namespace App\Media;

use App\Media\MediaInfo\AudioStreamInfo;
use App\Media\MediaInfo\StreamInfo;
use App\Media\MediaInfo\VideoStreamInfo;
use App\Models\IndexedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

/**
 * What the info page shows about a file, as labeled sections of terms and values, so the view only has to lay them
 * out. Anything we don't know is left out rather than shown empty.
 *
 * The file may not be in the index yet (or ever, if its directory hasn't been visited), in which case only what the
 * filesystem tells us is available.
 */
readonly class FileDetails
{
    public MediaContentKind $kind;

    public string $mimeType;

    public function __construct(
        public \SplFileInfo $info,
        public ?IndexedFile $file,
    ) {
        $this->mimeType = $file->mime_type ?? (mime_content_type($info->getPathname()) ?: 'application/octet-stream');
        $this->kind = $file->kind ?? MediaContentKind::guessForFile($this->mimeType, $info->getExtension());
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function sections(): array
    {
        $mediaInfo = $this->file?->media_info;

        $sections = [
            'File' => $this->fileSection($mediaInfo),
        ];

        // Images are probed as a single-frame video stream, which is already summarized as their dimensions.
        if ($mediaInfo !== null && ($this->kind === MediaContentKind::Video || $this->kind === MediaContentKind::Audio)) {
            // Streams of the same type stay in the file's order (usort is stable).
            $streams = $mediaInfo->streams;
            usort($streams, fn (StreamInfo $a, StreamInfo $b) => $a->codecType->displayOrder() <=> $b->codecType->displayOrder());

            $streamCounts = array_count_values(array_map(fn (StreamInfo $stream) => $stream->codecType->value, $streams));
            $streamNumbers = [];

            foreach ($streams as $stream) {
                $codecType = $stream->codecType->value;
                $label = ucfirst($codecType);

                // Only number the streams when there's more than one of a type.
                if ($streamCounts[$codecType] > 1) {
                    $streamNumbers[$codecType] = ($streamNumbers[$codecType] ?? 0) + 1;
                    $label .= ' '.$streamNumbers[$codecType];
                }

                $sections[$label] = $this->streamSection($stream);
            }
        }

        $fingerprint = $this->file?->fingerprint;
        if ($fingerprint !== null && !$fingerprint->isBlank()) {
            $sections['Fingerprints'] = array_filter([
                'OSHash' => $fingerprint->oshash,
                'PHash' => $fingerprint->phash,
            ]);
        }

        return array_filter($sections);
    }

    /**
     * @return array<string, string>
     */
    private function fileSection(?MediaInfo $mediaInfo): array
    {
        $videoStream = null;
        foreach ($mediaInfo->streams ?? [] as $stream) {
            if ($stream instanceof VideoStreamInfo) {
                $videoStream = $stream;
                break;
            }
        }

        return array_filter([
            'Name' => $this->info->getFilename(),
            'Type' => ucfirst($this->kind->displayName()).' ('.$this->mimeType.')',
            'Size' => Number::fileSize($this->info->getSize(), maxPrecision: 1),
            'Modified' => Carbon::createFromTimestamp($this->info->getMTime(), config('app.timezone'))->toDayDateTimeString(),
            'Title' => $mediaInfo?->title,
            'Dimensions' => $videoStream ? self::formatDimensions($videoStream) : null,
            'Duration' => isset($mediaInfo->durationMs) ? self::formatDuration($mediaInfo->durationMs) : null,
            'Bit rate' => isset($mediaInfo->bitRate) ? self::formatBitRate($mediaInfo->bitRate) : null,
            'Container' => $mediaInfo?->formatName,
            'Encoded' => isset($mediaInfo->encodingTimestamp)
                ? Carbon::createFromTimestamp($mediaInfo->encodingTimestamp, config('app.timezone'))->toDayDateTimeString()
                : null,
            'Comment' => $mediaInfo?->comment,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @return array<string, string>
     */
    private function streamSection(StreamInfo $stream): array
    {
        $codec = $stream->codecName;
        if ($codec !== null && $stream->profile !== null) {
            $codec .= ' ('.$stream->profile.')';
        }

        $details = [
            'Codec' => $codec,
            'Title' => $stream->title,
            'Language' => $stream->language,
        ];

        if ($stream instanceof VideoStreamInfo) {
            $details += [
                'Dimensions' => self::formatDimensions($stream),
                'Frame rate' => ($stream->frameRate !== null) ? $stream->frameRate->toFloat(3).' fps' : null,
                'Pixel format' => $stream->pixelFormat,
                'Rotation' => $stream->rotation ? $stream->rotation.'°' : null,
            ];
        } elseif ($stream instanceof AudioStreamInfo) {
            $details += [
                'Sample rate' => Number::format($stream->sampleRate / 1000, maxPrecision: 1).' kHz',
                'Channels' => $stream->channelLayout ?? (string) $stream->channels,
            ];
        }

        $details['Bit rate'] = ($stream->bitRate !== null) ? self::formatBitRate($stream->bitRate) : null;

        return array_filter($details, fn ($value) => $value !== null && $value !== '');
    }

    public static function formatDimensions(VideoStreamInfo $stream): string
    {
        // A rotated video is displayed the other way around.
        $quarterTurned = $stream->rotation !== null && abs($stream->rotation) % 180 === 90;

        return $quarterTurned
            ? $stream->height.' × '.$stream->width
            : $stream->width.' × '.$stream->height;
    }

    public static function formatDuration(int $durationMs): string
    {
        $seconds = intdiv($durationMs + 500, 1000);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $seconds %= 60;

        return ($hours > 0)
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $seconds)
            : sprintf('%d:%02d', $minutes, $seconds);
    }

    public static function formatBitRate(int $bitRate): string
    {
        return ($bitRate >= 1_000_000)
            ? Number::format($bitRate / 1_000_000, maxPrecision: 1).' Mb/s'
            : Number::format($bitRate / 1000, maxPrecision: 0).' kb/s';
    }
}

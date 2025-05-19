<?php

namespace App\Media\MediaInfo;

use App\Utilities\ArrayableWithoutNullValues;
use App\Utilities\Fraction;
use Illuminate\Contracts\Support\Arrayable;

readonly class StreamInfo implements Arrayable
{
    use ArrayableWithoutNullValues;

    public int $index;

    public StreamCodecType $codecType;

    public ?string $codecTag;

    public ?string $codecName;

    public ?string $profile;

    public ?int $level;

    public ?Fraction $frameRate;

    public ?int $durationMs;

    public ?int $bitRate;

    public ?int $rotation;

    // From tags:
    public ?string $title;

    public ?string $language;

    protected function fillFromArray(array $data): void
    {
        $this->codecTag = $data['codecTag'] ?? null;
        $this->codecName = $data['codecName'] ?? null;
        $this->profile = $data['profile'] ?? null;
        $this->level = $data['level'] ?? null;
        $this->frameRate = Fraction::fromArray($data['frameRate'] ?? null);
        $this->durationMs = $data['durationMs'] ?? null;
        $this->bitRate = $data['bitRate'] ?? null;
        $this->rotation = $data['rotation'] ?? null;
        $this->title = $data['title'] ?? null;
        $this->language = $data['language'] ?? null;
    }

    public static function fromArray(self|array $data): self
    {
        if ($data instanceof self) {
            return $data;
        }

        $codecType = StreamCodecType::from($data['codecType']);
        $self = match ($codecType) {
            StreamCodecType::Video => new VideoStreamInfo,
            StreamCodecType::Audio => new AudioStreamInfo,
            default => new self,
        };

        $self->index = $data['index'];
        $self->codecType = $codecType;
        $self->fillFromArray($data);

        return $self;
    }

    public static function fromProbeOutput(array $data): self
    {
        $rotation = null;
        foreach (($data['side_data_list'] ?? []) as $side_data) {
            if (isset($side_data['rotation'])) {
                $rotation = $side_data['rotation'];
                break;
            }
        }

        return self::fromArray([
            'index' => $data['index'],
            'codecType' => $data['codec_type'],
            'codecTag' => $data['codec_tag_string'] ?? null,
            'codecName' => $data['codec_name'] ?? null,
            'profile' => $data['profile'] ?? null,
            'level' => (isset($data['level']) && $data['level'] > -99) ? (int) $data['level'] : null,
            'frameRate' => Fraction::fromString($data['r_frame_rate'] ?? '0/0'),
            'durationMs' => isset($data['duration']) ? round($data['duration'] * 1000) : null,
            'bitRate' => isset($data['bit_rate']) ? (int) $data['bit_rate'] : null,
            'rotation' => $rotation,
            'title' => $data['tags']['title'] ?? null,
            'language' => $data['tags']['language'] ?? null,

            // Video
            'width' => $data['width'] ?? null,
            'height' => $data['height'] ?? null,
            'pixelFormat' => $data['pix_fmt'] ?? null,

            // Audio
            'sampleRate' => $data['sample_rate'] ?? null,
            'channels' => $data['channels'] ?? null,
            'channelLayout' => $data['channel_layout'] ?? null,
        ]);
    }
}

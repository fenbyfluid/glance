<?php

namespace App\Media\MediaInfo;

readonly class AudioStreamInfo extends StreamInfo
{
    public int $sampleRate;

    public int $channels;

    public ?string $channelLayout;

    protected function fillFromArray(array $data): void
    {
        parent::fillFromArray($data);

        $this->sampleRate = $data['sampleRate'];
        $this->channels = $data['channels'];
        $this->channelLayout = $data['channelLayout'] ?? null;
    }
}

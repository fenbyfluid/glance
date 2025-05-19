<?php

namespace App\Media\MediaInfo;

readonly class VideoStreamInfo extends StreamInfo
{
    public int $width;

    public int $height;

    public string $pixelFormat;

    protected function fillFromArray(array $data): void
    {
        parent::fillFromArray($data);

        $this->width = $data['width'];
        $this->height = $data['height'];
        $this->pixelFormat = $data['pixelFormat'];
    }
}

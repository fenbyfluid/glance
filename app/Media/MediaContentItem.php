<?php

namespace App\Media;

readonly class MediaContentItem
{
    public function __construct(
        public string $label,
        public string $path,
        public ?string $mimeType = null,
        public ?string $thumbnail = null,
    ) {}
}

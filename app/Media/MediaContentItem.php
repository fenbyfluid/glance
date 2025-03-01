<?php

namespace App\Media;

readonly class MediaContentItem
{
    public function __construct(public string $name, public ?string $thumbnail) {}
}

<?php

namespace App\Media;

class MediaContentItem
{
    public string $label {
        get => rtrim($this->path, '/');
    }

    public MediaContentKind $kind {
        get {
            if ($this->mimeType === null) {
                return str_ends_with($this->path, '/') ? MediaContentKind::Directory : MediaContentKind::Other;
            }

            return MediaContentKind::guessForFile($this->mimeType, strrchr(basename($this->path), '.'));
        }
    }

    public function __construct(
        readonly string $path,
        readonly public ?string $mimeType = null,
        readonly public ?string $thumbnail = null,
    ) {}
}

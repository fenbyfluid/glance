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

    /**
     * Whether the item is only visible through a shared link, not the user's own access (see PathAccess), so is marked.
     */
    public bool $isOnlyThroughLinks = false;

    public function __construct(
        public readonly string $path,
        public readonly int $mtime,
        public readonly ?string $mimeType = null,
        public readonly ?string $thumbnail = null,
        public readonly ?string $filmstrip = null,
    ) {}
}

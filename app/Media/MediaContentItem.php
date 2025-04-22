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

            $extension = strrchr($this->path, '.');
            $type = strtok($this->mimeType, '/');

            return match (true) {
                $extension === 'f4v' || $extension === 'mp4' => MediaContentKind::Video,
                $this->mimeType === 'application/vnd.rn-realmedia' => MediaContentKind::Video,
                $this->mimeType === 'application/pdf' => MediaContentKind::Pdf,
                $this->mimeType === 'text/html' => MediaContentKind::Html,
                $type === 'video' => MediaContentKind::Video,
                $type === 'audio' => MediaContentKind::Audio,
                $type === 'image' => MediaContentKind::Image,
                $type === 'text' => MediaContentKind::Text,
                default => MediaContentKind::Other,
            };
        }
    }

    public function __construct(
        readonly string $path,
        readonly public ?string $mimeType = null,
        readonly public ?string $thumbnail = null,
    ) {}
}

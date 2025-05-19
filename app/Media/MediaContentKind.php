<?php

namespace App\Media;

// These cases are ordered in display order.
enum MediaContentKind: string
{
    case Directory = 'directory';
    case Other = 'other';
    case Text = 'text';
    case Html = 'html';
    case Pdf = 'pdf';
    case Archive = 'archive';
    case Audio = 'audio';
    case Image = 'image';
    case Video = 'video';

    public function canThumbnail(): bool
    {
        return $this === self::Image || $this === self::Video;
    }

    public function canPerceptualHash(): bool
    {
        return $this === self::Image || $this === self::Video;
    }

    public function canProbeMediaInfo(): bool
    {
        return $this === self::Audio || $this === self::Image || $this === self::Video;
    }

    public function lightboxType(): ?string
    {
        // TODO: This is specific to our view layer and doesn't really belong here.
        return match ($this) {
            self::Text, self::Html => 'ajax',
            self::Pdf => 'iframe',
            self::Audio, self::Video => 'video',
            self::Image => 'image',
            default => null,
        };
    }

    public static function guessForFile(string $mimeType, string $extension): self
    {
        $type = strtok($mimeType, '/');

        return match (true) {
            $extension === 'f4v' || $extension === 'mp4' => self::Video,
            $mimeType === 'application/vnd.rn-realmedia' => self::Video,
            $mimeType === 'application/pdf' => self::Pdf,
            $mimeType === 'text/html' || $extension === 'html' => self::Html,
            $mimeType === 'application/x-wine-extension-ini' => self::Text,
            $mimeType === 'application/zip' || $mimeType === 'application/x-rar' => self::Archive,
            $type === 'video' => self::Video,
            $type === 'audio' => self::Audio,
            $type === 'image' => self::Image,
            $type === 'text' => self::Text,
            default => self::Other,
        };
    }
}

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
    case Audio = 'audio';
    case Image = 'image';
    case Video = 'video';

    public function canThumbnail(): bool
    {
        return $this === self::Video || $this === self::Image;
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
}
